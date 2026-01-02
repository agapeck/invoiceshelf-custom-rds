<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Updates the appointments.type enum to include all dental-specific types
     * that are validated in AppointmentRequest and displayed in Create.vue.
     */
    public function up(): void
    {
        // MySQL requires special handling for enum modifications
        DB::statement("ALTER TABLE appointments MODIFY COLUMN type ENUM(
            'consultation',
            'follow_up',
            'cleaning',
            'filling',
            'extraction',
            'root_canal',
            'crown_bridge',
            'denture',
            'whitening',
            'pediatric',
            'ortho_consult',
            'treatment',
            'emergency',
            'other'
        ) DEFAULT 'consultation'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: Reverting will fail if any appointments have the new types
        // This is intentional - data would be lost otherwise
        DB::statement("ALTER TABLE appointments MODIFY COLUMN type ENUM(
            'consultation',
            'follow_up',
            'treatment',
            'emergency',
            'other'
        ) DEFAULT 'consultation'");
    }
};
