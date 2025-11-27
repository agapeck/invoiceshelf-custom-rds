<?php

use App\Models\Invoice;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $invoices = \Illuminate\Support\Facades\DB::table('invoices')->get();

        foreach ($invoices as $invoice) {
            if ($invoice->exchange_rate) {
                \Illuminate\Support\Facades\DB::table('invoices')
                    ->where('id', $invoice->id)
                    ->update([
                        'base_due_amount' => $invoice->due_amount * $invoice->exchange_rate
                    ]);
            }
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
