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
            $table->boolean('enable_portal')->default(false)->change();
        });

        // Set all customers to enable_portal = false using raw DB query
        DB::table('customers')->update(['enable_portal' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
