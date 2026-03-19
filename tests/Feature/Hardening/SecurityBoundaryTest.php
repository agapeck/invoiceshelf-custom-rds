<?php

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\FileDisk;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->withHeaders([
        'company' => $user->companies()->first()->id,
    ]);
    Sanctum::actingAs($user, ['*']);
});

test('customer token invoice endpoint enforces expiry on JSON endpoint', function () {
    $invoice = Invoice::factory()->create();

    CompanySetting::setSettings([
        'automatically_expire_public_links' => 'YES',
        'link_expiry_days' => 1,
    ], $invoice->company_id);

    $emailLog = EmailLog::create([
        'from' => 'sender@example.com',
        'to' => 'customer@example.com',
        'subject' => 'Invoice',
        'body' => 'Invoice body',
        'mailable_type' => Invoice::class,
        'mailable_id' => $invoice->id,
        'token' => 'expired-token-123',
        'created_at' => now()->subDays(5),
    ]);

    get("/customer/invoices/{$emailLog->token}")
        ->assertStatus(403);
});

test('customer cannot access another customers invoice pdf by hash', function () {
    $companyId = User::find(1)->companies()->first()->id;

    $customerA = Customer::factory()->create([
        'company_id' => $companyId,
    ]);
    $customerB = Customer::factory()->create([
        'company_id' => $companyId,
    ]);

    $invoiceForCustomerB = Invoice::factory()->create([
        'company_id' => $companyId,
        'customer_id' => $customerB->id,
    ]);

    actingAs($customerA, 'customer');

    get("/invoices/pdf/{$invoiceForCustomerB->unique_hash}")
        ->assertStatus(403);
});

test('disk listing excludes disks from other companies', function () {
    $currentCompanyId = User::find(1)->companies()->first()->id;
    $otherCompany = Company::factory()->create();
    $foreignDisk = FileDisk::factory()->create([
        'company_id' => $otherCompany->id,
        'set_as_default' => false,
    ]);

    $response = getJson('/api/v1/disks')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($currentCompanyId)->not->toBe($otherCompany->id);
    expect($ids)->not->toContain($foreignDisk->id);
});
