<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update all OVERDUE invoices to SENT status with overdue flag
        DB::table('invoices')
            ->where('status', 'OVERDUE')
            ->update([
                'status' => 'SENT',
                'overdue' => true,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
