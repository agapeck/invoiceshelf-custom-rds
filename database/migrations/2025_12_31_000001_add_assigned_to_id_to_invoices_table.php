<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('assigned_to_id')->nullable()->after('creator_id');
            
            $table->foreign('assigned_to_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            
            $table->index('assigned_to_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['assigned_to_id']);
            $table->dropIndex(['assigned_to_id']);
            $table->dropColumn('assigned_to_id');
        });
    }
};
