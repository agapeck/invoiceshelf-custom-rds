<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only run if the base_amount column exists on invoice_items
        if (!Schema::hasColumn('invoice_items', 'base_amount')) {
            return;
        }
        
        // Get taxes where the related invoice_item has base_amount = null
        $taxes = DB::table('taxes')
            ->join('invoice_items', 'taxes.invoice_item_id', '=', 'invoice_items.id')
            ->whereNull('invoice_items.base_amount')
            ->select('taxes.*', 'invoice_items.exchange_rate as item_exchange_rate')
            ->get();

        foreach ($taxes as $tax) {
            $exchange_rate = $tax->item_exchange_rate ?? 1;
            DB::table('taxes')
                ->where('id', $tax->id)
                ->update([
                    'exchange_rate' => $exchange_rate,
                    'base_amount' => $tax->amount * $exchange_rate,
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
