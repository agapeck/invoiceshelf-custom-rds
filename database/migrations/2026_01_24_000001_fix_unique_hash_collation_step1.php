<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 1: Drop UNIQUE constraints on unique_hash columns
 * 
 * This allows the hash regeneration script to run without constraint violations.
 * The constraints will be re-added in step 2 after collation is changed.
 * 
 * Root Cause: The unique_hash columns use utf8mb4_unicode_ci (case-insensitive),
 * but Hashids generates mixed-case hashes. This causes case-insensitive collisions
 * where 'ABC' and 'abc' are considered duplicates, causing saveQuietly() to fail.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop unique constraint on invoices
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_unique_hash_unique');
        });

        // Drop unique constraint on estimates
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropUnique('estimates_unique_hash_unique');
        });

        // Drop unique constraint on payments
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_unique_hash_unique');
        });

        // Drop unique constraint on appointments
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique('appointments_unique_hash_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add unique constraints (case-insensitive - original state)
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
};
