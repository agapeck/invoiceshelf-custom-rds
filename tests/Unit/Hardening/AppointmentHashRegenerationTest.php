<?php

namespace Tests\Unit\Hardening;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Tests\TestCase;

class AppointmentHashRegenerationTest extends TestCase
{
    public function test_regenerate_missing_hashes_processes_all_records_without_chunk_skips(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required for database-backed tests.');
        }

        $this->artisan('migrate:fresh');
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);

        $admin = User::findOrFail(1);
        $companyId = (int) $admin->companies()->firstOrFail()->id;
        $customer = Customer::factory()->create(['company_id' => $companyId]);

        Appointment::factory()->count(205)->create([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'creator_id' => $admin->id,
        ]);

        Appointment::query()->update(['unique_hash' => null]);

        $results = Appointment::regenerateMissingHashes();

        $this->assertSame(205, $results['success']);
        $this->assertSame(0, $results['failed']);
        $this->assertSame(0, Appointment::whereNull('unique_hash')->orWhere('unique_hash', '')->count());
    }
}
