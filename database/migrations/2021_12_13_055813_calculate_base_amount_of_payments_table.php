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
        $payments = DB::table('payments')
            ->whereNotNull('exchange_rate')
            ->get();

        foreach ($payments as $payment) {
            DB::table('payments')
                ->where('id', $payment->id)
                ->update([
                    'base_amount' => $payment->exchange_rate * $payment->amount,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
