<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Vinkla\Hashids\Facades\Hashids;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Estimate;
use App\Models\Appointment;

/**
 * Step 2 (of 3): Regenerate ALL unique hashes
 * 
 * This migration regenerates hashes for ALL records in:
 * - invoices (including soft-deleted)
 * - payments (including soft-deleted)
 * - estimates (including soft-deleted)
 * - appointments
 * 
 * This runs AFTER constraints are dropped (step 1) and BEFORE 
 * collation change + constraint re-add (step 3).
 * 
 * Each hash is verified to decode back to the correct ID.
 */
return new class extends Migration
{
    /**
     * Table to Model class mapping for hash generation.
     */
    private array $tableModelMap = [
        'invoices' => Invoice::class,
        'payments' => Payment::class,
        'estimates' => Estimate::class,
        'appointments' => Appointment::class,
    ];

    /**
     * Stats for tracking progress.
     */
    private array $stats = [];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Log::info('Starting hash regeneration migration');

        // Initialize stats
        foreach (array_keys($this->tableModelMap) as $table) {
            $this->stats[$table] = ['processed' => 0, 'failed' => 0, 'skipped' => 0];
        }

        // Process each table
        foreach ($this->tableModelMap as $table => $modelClass) {
            $this->regenerateHashesForTable($table, $modelClass);
        }

        // Log summary
        Log::info('Hash regeneration completed', $this->stats);
        
        // Verify no duplicates before proceeding
        $this->verifyNoDuplicates();
    }

    /**
     * Regenerate hashes for a specific table.
     */
    private function regenerateHashesForTable(string $table, string $modelClass): void
    {
        // Safety check: table and column must exist
        if (!Schema::hasTable($table)) {
            Log::warning("Table {$table} does not exist, skipping hash regeneration");
            $this->stats[$table]['skipped'] = -1; // Mark as skipped
            return;
        }

        if (!Schema::hasColumn($table, 'unique_hash')) {
            Log::warning("Column unique_hash does not exist in {$table}, skipping");
            $this->stats[$table]['skipped'] = -1;
            return;
        }

        Log::info("Regenerating {$table} hashes...");
        
        DB::table($table)
            ->orderBy('id')
            ->chunk(100, function ($records) use ($table, $modelClass) {
                foreach ($records as $record) {
                    try {
                        $newHash = Hashids::connection($modelClass)->encode($record->id);
                        
                        // Verify hash decodes correctly
                        $decoded = Hashids::connection($modelClass)->decode($newHash);
                        if (empty($decoded) || $decoded[0] !== $record->id) {
                            Log::error("{$table} ID {$record->id}: Hash decode verification failed", [
                                'hash' => $newHash,
                                'decoded' => $decoded,
                            ]);
                            $this->stats[$table]['failed']++;
                            continue;
                        }
                        
                        DB::table($table)
                            ->where('id', $record->id)
                            ->update(['unique_hash' => $newHash]);
                        
                        $this->stats[$table]['processed']++;
                    } catch (\Throwable $e) {
                        Log::error("{$table} ID {$record->id}: Hash regeneration failed", [
                            'error' => $e->getMessage(),
                        ]);
                        $this->stats[$table]['failed']++;
                    }
                }
            });
        
        Log::info("{$table} hashes regenerated", $this->stats[$table]);
    }

    /**
     * Verify no duplicate hashes exist (case-sensitive check).
     * 
     * Uses parameterized table name validation to prevent SQL injection.
     */
    private function verifyNoDuplicates(): void
    {
        // Only check tables that exist and were processed
        $tablesToCheck = array_filter(
            array_keys($this->tableModelMap),
            fn($table) => Schema::hasTable($table) && Schema::hasColumn($table, 'unique_hash')
        );
        
        foreach ($tablesToCheck as $table) {
            // Validate table name is in our whitelist (defense against SQL injection)
            if (!array_key_exists($table, $this->tableModelMap)) {
                continue;
            }

            // Check for case-sensitive duplicates using BINARY
            // Table name is safe because it's from our whitelist
            $duplicates = DB::select(
                "SELECT MIN(unique_hash) as unique_hash, COUNT(*) as cnt
                 FROM `{$table}`
                 WHERE unique_hash IS NOT NULL
                 GROUP BY BINARY unique_hash
                 HAVING cnt > 1"
            );
            
            if (count($duplicates) > 0) {
                Log::error("Duplicate hashes found in {$table} after regeneration", [
                    'duplicates' => $duplicates,
                ]);
                throw new \RuntimeException("Duplicate hashes found in {$table}. Migration cannot continue.");
            }
        }
        
        Log::info('No duplicate hashes found - verification passed');
    }

    /**
     * Reverse the migrations.
     * 
     * Note: This cannot truly reverse the hash regeneration as the old hashes
     * are not stored. A backup should be restored if rollback is needed.
     */
    public function down(): void
    {
        Log::warning('Hash regeneration migration rolled back - hashes are NOT restored to original values');
        // Hashes cannot be automatically restored - use backup if needed
    }
};
