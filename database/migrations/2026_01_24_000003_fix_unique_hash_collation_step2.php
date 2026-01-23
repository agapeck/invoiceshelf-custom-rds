<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Step 2: Change collation to case-sensitive and re-add UNIQUE constraints
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
     * Run the migrations.
     */
    public function up(): void
    {
        // Change collation on invoices.unique_hash to case-sensitive
        DB::statement('ALTER TABLE invoices MODIFY unique_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
        
        // Change collation on estimates.unique_hash to case-sensitive
        DB::statement('ALTER TABLE estimates MODIFY unique_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
        
        // Change collation on payments.unique_hash to case-sensitive
        DB::statement('ALTER TABLE payments MODIFY unique_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
        
        // Change collation on appointments.unique_hash to case-sensitive (varchar 50)
        DB::statement('ALTER TABLE appointments MODIFY unique_hash VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');

        // Re-add unique constraints (now case-sensitive!)
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('unique_hash', 'invoices_unique_hash_unique');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->unique('unique_hash', 'estimates_unique_hash_unique');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unique('unique_hash', 'payments_unique_hash_unique');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->unique('unique_hash', 'appointments_unique_hash_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop unique constraints
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_unique_hash_unique');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropUnique('estimates_unique_hash_unique');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_unique_hash_unique');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique('appointments_unique_hash_unique');
        });

        // Revert collation to case-insensitive (original state)
        DB::statement('ALTER TABLE invoices MODIFY unique_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        DB::statement('ALTER TABLE estimates MODIFY unique_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        DB::statement('ALTER TABLE payments MODIFY unique_hash VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        DB::statement('ALTER TABLE appointments MODIFY unique_hash VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
    }
};
