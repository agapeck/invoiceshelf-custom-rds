<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('prefix')->nullable()->after('id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->mediumInteger('sequence_number')->unsigned()->nullable()->after('id');
            $table->mediumInteger('customer_sequence_number')->unsigned()->nullable()->after('sequence_number');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->mediumInteger('sequence_number')->unsigned()->nullable()->after('id');
            $table->mediumInteger('customer_sequence_number')->unsigned()->nullable()->after('sequence_number');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->mediumInteger('sequence_number')->unsigned()->nullable()->after('id');
            $table->mediumInteger('customer_sequence_number')->unsigned()->nullable()->after('sequence_number');
        });

        $user = DB::table('users')->where('role', 'super admin')->first();

        if ($user && $user->role == 'super admin') {
            $customers = DB::table('customers')->get();
            
            foreach ($customers as $customer) {
                // Process invoices
                $invoices = DB::table('invoices')
                    ->where('customer_id', $customer->id)
                    ->orderBy('id')
                    ->get();
                
                $customerSequence = 1;
                foreach ($invoices as $invoice) {
                    $invoiceNumber = explode('-', $invoice->invoice_number);
                    DB::table('invoices')
                        ->where('id', $invoice->id)
                        ->update([
                            'sequence_number' => intval(end($invoiceNumber)),
                            'customer_sequence_number' => $customerSequence,
                        ]);
                    $customerSequence++;
                }

                // Process estimates
                $estimates = DB::table('estimates')
                    ->where('customer_id', $customer->id)
                    ->orderBy('id')
                    ->get();
                
                $customerSequence = 1;
                foreach ($estimates as $estimate) {
                    $estimateNumber = explode('-', $estimate->estimate_number);
                    DB::table('estimates')
                        ->where('id', $estimate->id)
                        ->update([
                            'sequence_number' => intval(end($estimateNumber)),
                            'customer_sequence_number' => $customerSequence,
                        ]);
                    $customerSequence++;
                }

                // Process payments
                $payments = DB::table('payments')
                    ->where('customer_id', $customer->id)
                    ->orderBy('id')
                    ->get();
                
                $customerSequence = 1;
                foreach ($payments as $payment) {
                    $paymentNumber = explode('-', $payment->payment_number);
                    DB::table('payments')
                        ->where('id', $payment->id)
                        ->update([
                            'sequence_number' => intval(end($paymentNumber)),
                            'customer_sequence_number' => $customerSequence,
                        ]);
                    $customerSequence++;
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('sequence_number');
            $table->dropColumn('customer_sequence_number');
        });
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('sequence_number');
            $table->dropColumn('customer_sequence_number');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('sequence_number');
            $table->dropColumn('customer_sequence_number');
        });
    }
};
