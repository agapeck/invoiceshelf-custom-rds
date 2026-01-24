<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step 3 (of 3): Change collation to case-sensitive and re-add UNIQUE constraints
 * 
 * This migration:
 * 1. Changes the unique_hash column collation from utf8mb4_unicode_ci to utf8mb4_bin
 * 2. Re-adds the UNIQUE constraints
 * 
 * utf8mb4_bin is a binary collation that is case-sensitive, so 'ABC' and 'abc'
 * are treated as different values. This prevents the case-insensitive collision
 * issue that was causing new invoices/payments to fail hash generation.
 * 
 * IMPORTANT: Run the hash regeneration script BEFORE this migration!
 */
return new class extends Migration
{
    /**
     * Table configurations: table_name => varchar_length
     */
    private array $tableConfigs = [
        'invoices' => 255,
        'estimates' => 255,
        'payments' => 255,
        'appointments' => 50,
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tableConfigs as $table => $varcharLength) {
            $this->changeCollationAndAddConstraint($table, $varcharLength, 'utf8mb4_bin');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tableConfigs as $table => $varcharLength) {
            $this->dropConstraintAndRevertCollation($table, $varcharLength, 'utf8mb4_unicode_ci');
        }
    }

    /**
     * Change collation to case-sensitive and add unique constraint.
     */
    private function changeCollationAndAddConstraint(string $table, int $varcharLength, string $collation): void
    {
        // Safety check: table and column must exist
        if (!Schema::hasTable($table)) {
            Log::warning("Table {$table} does not exist, skipping collation change");
            return;
        }

        if (!Schema::hasColumn($table, 'unique_hash')) {
            Log::warning("Column unique_hash does not exist in {$table}, skipping");
            return;
        }

        // Change collation
        DB::statement(
            "ALTER TABLE `{$table}` MODIFY `unique_hash` VARCHAR({$varcharLength}) CHARACTER SET utf8mb4 COLLATE {$collation} NULL"
        );

        // Add unique constraint if it doesn't exist
        $indexName = "{$table}_unique_hash_unique";
        if (!$this->indexExists($table, $indexName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->unique('unique_hash', $indexName);
            });
        }
    }

    /**
     * Drop unique constraint and revert collation.
     */
    private function dropConstraintAndRevertCollation(string $table, int $varcharLength, string $collation): void
    {
        // Safety check: table and column must exist
        if (!Schema::hasTable($table)) {
            return;
        }

        if (!Schema::hasColumn($table, 'unique_hash')) {
            return;
        }

        // Drop unique constraint if it exists
        $indexName = "{$table}_unique_hash_unique";
        if ($this->indexExists($table, $indexName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropUnique($indexName);
            });
        }

        // Revert collation
        DB::statement(
            "ALTER TABLE `{$table}` MODIFY `unique_hash` VARCHAR({$varcharLength}) CHARACTER SET utf8mb4 COLLATE {$collation} NULL"
        );
    }

    /**
     * Check if an index exists on a table.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }
};
