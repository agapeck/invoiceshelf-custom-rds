<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    private array $stats = [
        'invoices' => ['processed' => 0, 'failed' => 0],
        'payments' => ['processed' => 0, 'failed' => 0],
        'estimates' => ['processed' => 0, 'failed' => 0],
        'appointments' => ['processed' => 0, 'failed' => 0],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Log::info('Starting hash regeneration migration');

        // Process each table
        $this->regenerateInvoiceHashes();
        $this->regeneratePaymentHashes();
        $this->regenerateEstimateHashes();
        $this->regenerateAppointmentHashes();

        // Log summary
        Log::info('Hash regeneration completed', $this->stats);
        
        // Verify no duplicates before proceeding
        $this->verifyNoDuplicates();
    }

    /**
     * Regenerate invoice hashes (including soft-deleted)
     */
    private function regenerateInvoiceHashes(): void
    {
        Log::info('Regenerating invoice hashes...');
        
        // Get ALL invoices including soft-deleted
        DB::table('invoices')
            ->orderBy('id')
            ->chunk(100, function ($invoices) {
                foreach ($invoices as $invoice) {
                    try {
                        $newHash = Hashids::connection(Invoice::class)->encode($invoice->id);
                        
                        // Verify hash decodes correctly
                        $decoded = Hashids::connection(Invoice::class)->decode($newHash);
                        if (empty($decoded) || $decoded[0] !== $invoice->id) {
                            Log::error("Invoice {$invoice->id}: Hash decode verification failed", [
                                'hash' => $newHash,
                                'decoded' => $decoded,
                            ]);
                            $this->stats['invoices']['failed']++;
                            continue;
                        }
                        
                        DB::table('invoices')
                            ->where('id', $invoice->id)
                            ->update(['unique_hash' => $newHash]);
                        
                        $this->stats['invoices']['processed']++;
                    } catch (\Throwable $e) {
                        Log::error("Invoice {$invoice->id}: Hash regeneration failed", [
                            'error' => $e->getMessage(),
                        ]);
                        $this->stats['invoices']['failed']++;
                    }
                }
            });
        
        Log::info("Invoice hashes regenerated", $this->stats['invoices']);
    }

    /**
     * Regenerate payment hashes (including soft-deleted)
     */
    private function regeneratePaymentHashes(): void
    {
        Log::info('Regenerating payment hashes...');
        
        DB::table('payments')
            ->orderBy('id')
            ->chunk(100, function ($payments) {
                foreach ($payments as $payment) {
                    try {
                        $newHash = Hashids::connection(Payment::class)->encode($payment->id);
                        
                        // Verify hash decodes correctly
                        $decoded = Hashids::connection(Payment::class)->decode($newHash);
                        if (empty($decoded) || $decoded[0] !== $payment->id) {
                            Log::error("Payment {$payment->id}: Hash decode verification failed", [
                                'hash' => $newHash,
                                'decoded' => $decoded,
                            ]);
                            $this->stats['payments']['failed']++;
                            continue;
                        }
                        
                        DB::table('payments')
                            ->where('id', $payment->id)
                            ->update(['unique_hash' => $newHash]);
                        
                        $this->stats['payments']['processed']++;
                    } catch (\Throwable $e) {
                        Log::error("Payment {$payment->id}: Hash regeneration failed", [
                            'error' => $e->getMessage(),
                        ]);
                        $this->stats['payments']['failed']++;
                    }
                }
            });
        
        Log::info("Payment hashes regenerated", $this->stats['payments']);
    }

    /**
     * Regenerate estimate hashes (including soft-deleted)
     */
    private function regenerateEstimateHashes(): void
    {
        Log::info('Regenerating estimate hashes...');
        
        DB::table('estimates')
            ->orderBy('id')
            ->chunk(100, function ($estimates) {
                foreach ($estimates as $estimate) {
                    try {
                        $newHash = Hashids::connection(Estimate::class)->encode($estimate->id);
                        
                        // Verify hash decodes correctly
                        $decoded = Hashids::connection(Estimate::class)->decode($newHash);
                        if (empty($decoded) || $decoded[0] !== $estimate->id) {
                            Log::error("Estimate {$estimate->id}: Hash decode verification failed", [
                                'hash' => $newHash,
                                'decoded' => $decoded,
                            ]);
                            $this->stats['estimates']['failed']++;
                            continue;
                        }
                        
                        DB::table('estimates')
                            ->where('id', $estimate->id)
                            ->update(['unique_hash' => $newHash]);
                        
                        $this->stats['estimates']['processed']++;
                    } catch (\Throwable $e) {
                        Log::error("Estimate {$estimate->id}: Hash regeneration failed", [
                            'error' => $e->getMessage(),
                        ]);
                        $this->stats['estimates']['failed']++;
                    }
                }
            });
        
        Log::info("Estimate hashes regenerated", $this->stats['estimates']);
    }

    /**
     * Regenerate appointment hashes
     */
    private function regenerateAppointmentHashes(): void
    {
        Log::info('Regenerating appointment hashes...');
        
        DB::table('appointments')
            ->orderBy('id')
            ->chunk(100, function ($appointments) {
                foreach ($appointments as $appointment) {
                    try {
                        $newHash = Hashids::connection(Appointment::class)->encode($appointment->id);
                        
                        // Verify hash decodes correctly
                        $decoded = Hashids::connection(Appointment::class)->decode($newHash);
                        if (empty($decoded) || $decoded[0] !== $appointment->id) {
                            Log::error("Appointment {$appointment->id}: Hash decode verification failed", [
                                'hash' => $newHash,
                                'decoded' => $decoded,
                            ]);
                            $this->stats['appointments']['failed']++;
                            continue;
                        }
                        
                        DB::table('appointments')
                            ->where('id', $appointment->id)
                            ->update(['unique_hash' => $newHash]);
                        
                        $this->stats['appointments']['processed']++;
                    } catch (\Throwable $e) {
                        Log::error("Appointment {$appointment->id}: Hash regeneration failed", [
                            'error' => $e->getMessage(),
                        ]);
                        $this->stats['appointments']['failed']++;
                    }
                }
            });
        
        Log::info("Appointment hashes regenerated", $this->stats['appointments']);
    }

    /**
     * Verify no duplicate hashes exist (case-sensitive check)
     */
    private function verifyNoDuplicates(): void
    {
        $tables = ['invoices', 'estimates', 'payments', 'appointments'];
        
        foreach ($tables as $table) {
            // Check for case-sensitive duplicates using BINARY
            $duplicates = DB::select("
                SELECT unique_hash, COUNT(*) as count 
                FROM {$table} 
                WHERE unique_hash IS NOT NULL 
                GROUP BY BINARY unique_hash 
                HAVING count > 1
            ");
            
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
