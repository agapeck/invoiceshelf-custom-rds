<?php

namespace Tests\Feature\Hardening;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvailableSlotsCompanyIsolationTest extends TestCase
{
    public function test_available_slots_uses_header_company_context_even_when_query_param_company_id_is_sent(): void
    {
        $this->requireConfiguredDatabaseDriver();

        $this->artisan('migrate:fresh');
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        $admin = User::findOrFail(1);
        $companyOneId = (int) $admin->companies()->firstOrFail()->id;
        $companyTwo = Company::factory()->create();
        $companyTwoCustomer = Customer::factory()->create(['company_id' => $companyTwo->id]);

        // Block the 09:00 slot in company #2 only.
        Appointment::factory()->create([
            'company_id' => $companyTwo->id,
            'customer_id' => $companyTwoCustomer->id,
            'creator_id' => $admin->id,
            'appointment_date' => now()->addDay()->setTime(9, 0, 0),
            'duration_minutes' => 30,
            'status' => 'scheduled',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->withHeaders([
            'company' => (string) $companyOneId,
        ])->getJson(sprintf(
            '/api/v1/appointments/available-slots?date=%s&company_id=%d',
            now()->addDay()->toDateString(),
            $companyTwo->id
        ));

        $response->assertOk();
        $response->assertJsonFragment(['09:00']);
    }
}
