<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('file_disks', 'company_id')) {
            Schema::table('file_disks', function (Blueprint $table) {
                $table->unsignedInteger('company_id')->nullable()->after('set_as_default');
            });

            Schema::table('file_disks', function (Blueprint $table) {
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            });
        }

        try {
            Schema::table('file_disks', function (Blueprint $table) {
                $table->index(['company_id', 'driver'], 'file_disks_company_driver_idx');
            });
        } catch (\Throwable $e) {
            // Ignore if index already exists.
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('file_disks', 'company_id')) {
            return;
        }

        try {
            Schema::table('file_disks', function (Blueprint $table) {
                $table->dropIndex('file_disks_company_driver_idx');
            });
        } catch (\Throwable $e) {
            // Ignore if index is missing.
        }

        try {
            Schema::table('file_disks', function (Blueprint $table) {
                $table->dropForeign(['company_id']);
            });
        } catch (\Throwable $e) {
            // Ignore if foreign key is missing.
        }

        Schema::table('file_disks', function (Blueprint $table) {
            $table->dropColumn('company_id');
        });
    }
};
