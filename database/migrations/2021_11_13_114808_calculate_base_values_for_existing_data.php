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
        $user = DB::table('users')->where('role', 'super admin')->first();

        if ($user) {
            $companyUser = DB::table('company_user')->where('user_id', $user->id)->first();
            
            if (!$companyUser) {
                return;
            }
            
            $companyId = $companyUser->company_id;

            $currencySetting = DB::table('company_settings')
                ->where('company_id', $companyId)
                ->where('option', 'currency')
                ->first();
            
            $currency_id = $currencySetting ? $currencySetting->value : null;

            // Update items
            DB::table('items')->update(['currency_id' => $currency_id]);

            // Process customers
            $customers = DB::table('customers')->get();

            foreach ($customers as $customer) {
                // Process invoices
                $invoices = DB::table('invoices')->where('customer_id', $customer->id)->get();
                foreach ($invoices as $invoice) {
                    if ($customer->currency_id == $currency_id) {
                        DB::table('invoices')
                            ->where('id', $invoice->id)
                            ->update([
                                'currency_id' => $currency_id,
                                'exchange_rate' => 1,
                                'base_discount_val' => $invoice->sub_total,
                                'base_sub_total' => $invoice->sub_total,
                                'base_total' => $invoice->total,
                                'base_tax' => $invoice->tax,
                                'base_due_amount' => $invoice->due_amount,
                            ]);
                    } else {
                        DB::table('invoices')
                            ->where('id', $invoice->id)
                            ->update(['currency_id' => $customer->currency_id]);
                    }
                    $this->processItems($invoice->id, 'invoice_id');
                }

                // Process expenses
                $expenses = DB::table('expenses')->where('customer_id', $customer->id)->get();
                foreach ($expenses as $expense) {
                    DB::table('expenses')
                        ->where('id', $expense->id)
                        ->update([
                            'currency_id' => $currency_id,
                            'exchange_rate' => 1,
                            'base_amount' => $expense->amount,
                        ]);
                }

                // Process estimates
                $estimates = DB::table('estimates')->where('customer_id', $customer->id)->get();
                foreach ($estimates as $estimate) {
                    if ($customer->currency_id == $currency_id) {
                        DB::table('estimates')
                            ->where('id', $estimate->id)
                            ->update([
                                'currency_id' => $currency_id,
                                'exchange_rate' => 1,
                                'base_discount_val' => $estimate->sub_total,
                                'base_sub_total' => $estimate->sub_total,
                                'base_total' => $estimate->total,
                                'base_tax' => $estimate->tax,
                            ]);
                    } else {
                        DB::table('estimates')
                            ->where('id', $estimate->id)
                            ->update(['currency_id' => $customer->currency_id]);
                    }
                    $this->processItems($estimate->id, 'estimate_id');
                }

                // Process payments
                $payments = DB::table('payments')->where('customer_id', $customer->id)->get();
                foreach ($payments as $payment) {
                    if ($customer->currency_id == $currency_id) {
                        DB::table('payments')
                            ->where('id', $payment->id)
                            ->update([
                                'currency_id' => $currency_id,
                                'base_amount' => $payment->amount,
                                'exchange_rate' => 1,
                            ]);
                    } else {
                        DB::table('payments')
                            ->where('id', $payment->id)
                            ->update(['currency_id' => $customer->currency_id]);
                    }
                }
            }
        }
    }

    private function processItems($modelId, $foreignKey)
    {
        $tableName = $foreignKey === 'invoice_id' ? 'invoice_items' : 'estimate_items';
        $model = DB::table($foreignKey === 'invoice_id' ? 'invoices' : 'estimates')->find($modelId);
        
        if (!$model) {
            return;
        }
        
        $exchange_rate = $model->exchange_rate ?? 1;
        $currency_id = $model->currency_id;
        
        $items = DB::table($tableName)->where($foreignKey, $modelId)->get();
        
        foreach ($items as $item) {
            DB::table($tableName)
                ->where('id', $item->id)
                ->update([
                    'exchange_rate' => $exchange_rate,
                    'base_discount_val' => ($item->discount_val ?? 0) * $exchange_rate,
                    'base_price' => ($item->price ?? 0) * $exchange_rate,
                    'base_tax' => ($item->tax ?? 0) * $exchange_rate,
                    'base_total' => ($item->total ?? 0) * $exchange_rate,
                ]);

            // Process taxes for this item
            $taxes = DB::table('taxes')->where($foreignKey === 'invoice_id' ? 'invoice_item_id' : 'estimate_item_id', $item->id)->get();
            foreach ($taxes as $tax) {
                DB::table('taxes')
                    ->where('id', $tax->id)
                    ->update([
                        'currency_id' => $currency_id,
                        'exchange_rate' => $exchange_rate,
                        'base_amount' => ($tax->amount ?? 0) * $exchange_rate,
                    ]);
            }
        }

        // Process model-level taxes
        $modelTaxes = DB::table('taxes')->where($foreignKey, $modelId)->get();
        foreach ($modelTaxes as $tax) {
            DB::table('taxes')
                ->where('id', $tax->id)
                ->update([
                    'currency_id' => $currency_id,
                    'exchange_rate' => $exchange_rate,
                    'base_amount' => ($tax->amount ?? 0) * $exchange_rate,
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
