<?php

namespace Tests\Feature\Hardening;

use App\Models\Address;
use App\Models\Customer;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileAddressMappingTest extends TestCase
{
    public function test_profile_update_writes_billing_and_shipping_to_correct_address_types(): void
    {
        $this->requireConfiguredDatabaseDriver();

        $this->artisan('migrate:fresh');
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        $customer = Customer::factory()->create();

        Sanctum::actingAs($customer, ['*'], 'customer');

        $response = $this->withHeaders([
            'company' => (string) $customer->company_id,
        ])->postJson(sprintf('/api/v1/%s/customer/profile', $customer->company->slug), [
            'billing' => [
                'name' => 'Billing Person',
                'address_street_1' => 'Billing Street',
            ],
            'shipping' => [
                'name' => 'Shipping Person',
                'address_street_1' => 'Shipping Street',
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('addresses', [
            'customer_id' => $customer->id,
            'type' => Address::BILLING_TYPE,
            'name' => 'Billing Person',
            'address_street_1' => 'Billing Street',
        ]);

        $this->assertDatabaseHas('addresses', [
            'customer_id' => $customer->id,
            'type' => Address::SHIPPING_TYPE,
            'name' => 'Shipping Person',
            'address_street_1' => 'Shipping Street',
        ]);
    }
}
