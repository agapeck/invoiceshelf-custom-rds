<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Ensure column exists before adding unique index
            if (! Schema::hasColumn('appointments', 'unique_hash')) {
                $table->string('unique_hash', 50)->nullable()->after('creator_id');
            }
        });
        
        // Add unique index (ignore if already exists from previous migration)
        try {
            Schema::table('appointments', function (Blueprint $table) {
                $table->unique('unique_hash', 'appointments_unique_hash_unique');
            });
        } catch (\Throwable $e) {
            // Index already exists, ignore
        }
    }

    public function down(): void
    {
        try {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropUnique('appointments_unique_hash_unique');
            });
        } catch (\Throwable $e) {
            // Index doesn't exist, ignore
        }
    }
};
