<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Sets default type value for existing appointments that have null type.
     */
    public function up(): void
    {
        DB::table('appointments')
            ->where(function ($query) {
                $query->whereNull('type')
                    ->orWhere('type', '');
            })
            ->update(['type' => 'consultation']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No rollback needed - we can't know which records had null type originally
    }
};
