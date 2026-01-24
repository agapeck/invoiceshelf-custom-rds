<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 1: Drop UNIQUE constraints on unique_hash columns
 * 
 * This allows the hash regeneration script to run without constraint violations.
 * The constraints will be re-added in step 3 after collation is changed.
 * 
 * Root Cause: The unique_hash columns use utf8mb4_unicode_ci (case-insensitive),
 * but Hashids generates mixed-case hashes. This causes case-insensitive collisions
 * where 'ABC' and 'abc' are considered duplicates, causing saveQuietly() to fail.
 */
return new class extends Migration
{
    /**
     * Tables that need unique_hash constraint fixes.
     */
    private array $tables = ['invoices', 'estimates', 'payments', 'appointments'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            $this->dropUniqueConstraintIfExists($table);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            $this->addUniqueConstraintIfNotExists($table);
        }
    }

    /**
     * Safely drop unique constraint on unique_hash column if it exists.
     */
    private function dropUniqueConstraintIfExists(string $table): void
    {
        // Check if table and column exist
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'unique_hash')) {
            return;
        }

        $indexName = "{$table}_unique_hash_unique";
        
        // Check if the index exists before trying to drop it
        if ($this->indexExists($table, $indexName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropUnique($indexName);
            });
        }
    }

    /**
     * Safely add unique constraint on unique_hash column if it doesn't exist.
     */
    private function addUniqueConstraintIfNotExists(string $table): void
    {
        // Check if table and column exist
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'unique_hash')) {
            return;
        }

        $indexName = "{$table}_unique_hash_unique";
        
        // Check if the index already exists before trying to add it
        if (!$this->indexExists($table, $indexName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->unique('unique_hash', $indexName);
            });
        }
    }

    /**
     * Check if an index exists on a table.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }
};
