<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('invoices', ['company_id', 'deleted_at', 'invoice_date'], 'invoices_company_deleted_invoice_date_idx');
        $this->addIndex('invoices', ['company_id', 'deleted_at', 'paid_status'], 'invoices_company_deleted_paid_status_idx');
        $this->addIndex('estimates', ['company_id', 'deleted_at', 'estimate_date'], 'estimates_company_deleted_estimate_date_idx');
        $this->addIndex('payments', ['company_id', 'deleted_at', 'payment_date'], 'payments_company_deleted_payment_date_idx');
        $this->addIndex('expenses', ['company_id', 'deleted_at', 'expense_date'], 'expenses_company_deleted_expense_date_idx');
        $this->addIndex('customers', ['company_id', 'deleted_at', 'name'], 'customers_company_deleted_name_idx');
        $this->addIndex('appointments', ['company_id', 'appointment_date', 'status'], 'appointments_company_date_status_idx');
        $this->addIndex('file_disks', ['company_id', 'driver'], 'file_disks_company_driver_idx');
    }

    public function down(): void
    {
        $this->dropIndex('invoices', 'invoices_company_deleted_invoice_date_idx');
        $this->dropIndex('invoices', 'invoices_company_deleted_paid_status_idx');
        $this->dropIndex('estimates', 'estimates_company_deleted_estimate_date_idx');
        $this->dropIndex('payments', 'payments_company_deleted_payment_date_idx');
        $this->dropIndex('expenses', 'expenses_company_deleted_expense_date_idx');
        $this->dropIndex('customers', 'customers_company_deleted_name_idx');
        $this->dropIndex('appointments', 'appointments_company_date_status_idx');
        $this->dropIndex('file_disks', 'file_disks_company_driver_idx');
    }

    private function addIndex(string $tableName, array $columns, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
                $table->index($columns, $indexName);
            });
        } catch (\Throwable $e) {
            // Intentionally ignored to keep migration idempotent across environments.
        }
    }

    private function dropIndex(string $tableName, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            // Intentionally ignored if index was not created.
        }
    }
};
