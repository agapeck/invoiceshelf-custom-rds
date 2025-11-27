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
            
            if ($companyUser) {
                $companyId = $companyUser->company_id;
                
                $currencySetting = DB::table('company_settings')
                    ->where('company_id', $companyId)
                    ->where('option', 'currency')
                    ->first();
                
                $currency_id = $currencySetting ? $currencySetting->value : null;

                $expenses = DB::table('expenses')
                    ->where('company_id', $companyId)
                    ->whereNull('currency_id')
                    ->get();
                
                foreach ($expenses as $expense) {
                    DB::table('expenses')
                        ->where('id', $expense->id)
                        ->update([
                            'currency_id' => $currency_id,
                            'exchange_rate' => 1,
                            'base_amount' => $expense->amount,
                        ]);
                }
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
