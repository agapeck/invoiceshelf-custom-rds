<?php

namespace Tests\Unit\Hardening;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FileDisk;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Tests\TestCase;

class QueryHardeningTest extends TestCase
{
    public function test_invoice_apply_filters_ignores_untrusted_order_by_field(): void
    {
        $this->requireConfiguredDatabaseDriver();

        $query = Invoice::query()->applyFilters([
            'orderByField' => 'id desc, (select 1)',
            'orderBy' => 'asc',
        ]);

        $sql = $query->toSql();

        $this->assertStringContainsStringIgnoringCase('sequence_number', $sql);
        $this->assertStringNotContainsString('(select 1)', strtolower($sql));
    }

    public function test_appointment_apply_filters_ignores_untrusted_order_by_field(): void
    {
        $this->requireConfiguredDatabaseDriver();

        $query = Appointment::query()->applyFilters([
            'orderByField' => 'appointment_date desc, (select 1)',
            'orderBy' => 'asc',
        ]);

        $sql = $query->toSql();

        $this->assertStringContainsStringIgnoringCase('appointment_date', $sql);
        $this->assertStringNotContainsString('(select 1)', strtolower($sql));
    }

    public function test_expense_search_cannot_escape_company_filter_via_or_where(): void
    {
        $this->requireConfiguredDatabaseDriver();

        $this->artisan('migrate:fresh');
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        $admin = User::findOrFail(1);
        $companyOneId = (int) $admin->companies()->firstOrFail()->id;
        $companyTwo = Company::factory()->create();

        $customerOne = Customer::factory()->create(['company_id' => $companyOneId]);
        $customerTwo = Customer::factory()->create(['company_id' => $companyTwo->id]);
        $categoryOne = ExpenseCategory::factory()->create(['company_id' => $companyOneId]);
        $categoryTwo = ExpenseCategory::factory()->create(['company_id' => $companyTwo->id]);

        Expense::factory()->create([
            'company_id' => $companyOneId,
            'customer_id' => $customerOne->id,
            'expense_category_id' => $categoryOne->id,
            'notes' => 'safe company one note',
        ]);

        $leakExpense = Expense::factory()->create([
            'company_id' => $companyTwo->id,
            'customer_id' => $customerTwo->id,
            'expense_category_id' => $categoryTwo->id,
            'notes' => 'tenant leak marker',
        ]);

        $foundIds = Expense::query()
            ->whereCompanyId($companyOneId)
            ->applyFilters(['search' => 'tenant leak marker'])
            ->pluck('id');

        $this->assertFalse($foundIds->contains($leakExpense->id));
    }

    public function test_file_disk_search_cannot_escape_company_filter_via_or_where(): void
    {
        $this->requireConfiguredDatabaseDriver();

        $this->artisan('migrate:fresh');
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        $admin = User::findOrFail(1);
        $companyOneId = (int) $admin->companies()->firstOrFail()->id;
        $companyTwo = Company::factory()->create();

        FileDisk::factory()->create([
            'company_id' => $companyOneId,
            'name' => 'company one disk',
            'driver' => 'local',
        ]);

        $leakDisk = FileDisk::factory()->create([
            'company_id' => $companyTwo->id,
            'name' => 'tenant leak disk',
            'driver' => 's3',
        ]);

        $foundIds = FileDisk::query()
            ->where('company_id', $companyOneId)
            ->applyFilters(['search' => 'tenant leak disk'])
            ->pluck('id');

        $this->assertFalse($foundIds->contains($leakDisk->id));
    }
}
