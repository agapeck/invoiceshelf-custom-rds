<?php

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\FileDisk;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Appointment;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::query()->firstOrFail();
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
    $companyId = User::query()->firstOrFail()->companies()->firstOrFail()->id;

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
    $currentCompanyId = User::query()->firstOrFail()->companies()->firstOrFail()->id;
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

test('admin cannot view invoice pdf outside active company context', function () {
    $user = User::query()->firstOrFail();
    $activeCompanyId = (int) $user->companies()->firstOrFail()->id;
    $otherCompany = Company::factory()->create();
    $user->companies()->syncWithoutDetaching([$otherCompany->id]);

    $otherCustomer = Customer::factory()->create([
        'company_id' => $otherCompany->id,
    ]);

    $invoice = Invoice::factory()->create([
        'company_id' => $otherCompany->id,
        'customer_id' => $otherCustomer->id,
    ]);

    get("/invoices/pdf/{$invoice->unique_hash}?preview=1", [
        'company' => (string) $activeCompanyId,
    ])->assertForbidden();
});

test('admin cannot view estimate pdf outside active company context', function () {
    $user = User::query()->firstOrFail();
    $activeCompanyId = (int) $user->companies()->firstOrFail()->id;
    $otherCompany = Company::factory()->create();
    $user->companies()->syncWithoutDetaching([$otherCompany->id]);

    $otherCustomer = Customer::factory()->create([
        'company_id' => $otherCompany->id,
    ]);

    $estimate = Estimate::factory()->create([
        'company_id' => $otherCompany->id,
        'customer_id' => $otherCustomer->id,
    ]);

    get("/estimates/pdf/{$estimate->unique_hash}?preview=1", [
        'company' => (string) $activeCompanyId,
    ])->assertForbidden();
});

test('admin cannot view payment pdf outside active company context', function () {
    $user = User::query()->firstOrFail();
    $activeCompanyId = (int) $user->companies()->firstOrFail()->id;
    $otherCompany = Company::factory()->create();
    $user->companies()->syncWithoutDetaching([$otherCompany->id]);

    $otherCustomer = Customer::factory()->create([
        'company_id' => $otherCompany->id,
    ]);

    $payment = Payment::factory()->create([
        'company_id' => $otherCompany->id,
        'customer_id' => $otherCustomer->id,
    ]);

    get("/payments/pdf/{$payment->unique_hash}?preview=1", [
        'company' => (string) $activeCompanyId,
    ])->assertForbidden();
});

test('admin cannot view appointment pdf outside active company context', function () {
    $user = User::query()->firstOrFail();
    $activeCompanyId = (int) $user->companies()->firstOrFail()->id;
    $otherCompany = Company::factory()->create();
    $user->companies()->syncWithoutDetaching([$otherCompany->id]);

    $otherCustomer = Customer::factory()->create([
        'company_id' => $otherCompany->id,
    ]);

    $appointment = Appointment::factory()->create([
        'company_id' => $otherCompany->id,
        'customer_id' => $otherCustomer->id,
        'creator_id' => $user->id,
    ]);

    get("/appointments/pdf/{$appointment->unique_hash}?preview=1", [
        'company' => (string) $activeCompanyId,
    ])->assertForbidden();
});

test('admin cannot view customer outside active company context even with membership', function () {
    $user = User::query()->firstOrFail();
    $activeCompanyId = (int) $user->companies()->firstOrFail()->id;
    $otherCompany = Company::factory()->create();
    $user->companies()->syncWithoutDetaching([$otherCompany->id]);

    $otherCustomer = Customer::factory()->create([
        'company_id' => $otherCompany->id,
    ]);

    getJson("/api/v1/customers/{$otherCustomer->id}", [
        'company' => (string) $activeCompanyId,
    ])->assertForbidden();
});

test('admin can view invoice pdf using company query parameter when header is missing', function () {
    $user = User::query()->firstOrFail();
    $activeCompanyId = (int) $user->companies()->firstOrFail()->id;

    $customer = Customer::factory()->create([
        'company_id' => $activeCompanyId,
    ]);

    $invoice = Invoice::factory()->create([
        'company_id' => $activeCompanyId,
        'customer_id' => $customer->id,
    ]);

    get("/invoices/pdf/{$invoice->unique_hash}?preview=1&company={$activeCompanyId}", [
        'company' => '',
    ])->assertOk();
});

test('admin can view estimate pdf using company query parameter when header is missing', function () {
    $user = User::query()->firstOrFail();
    $activeCompanyId = (int) $user->companies()->firstOrFail()->id;

    $customer = Customer::factory()->create([
        'company_id' => $activeCompanyId,
    ]);

    $estimate = Estimate::factory()->create([
        'company_id' => $activeCompanyId,
        'customer_id' => $customer->id,
    ]);

    get("/estimates/pdf/{$estimate->unique_hash}?preview=1&company={$activeCompanyId}", [
        'company' => '',
    ])->assertOk();
});

test('admin can view payment pdf using company query parameter when header is missing', function () {
    $user = User::query()->firstOrFail();
    $activeCompanyId = (int) $user->companies()->firstOrFail()->id;

    $customer = Customer::factory()->create([
        'company_id' => $activeCompanyId,
    ]);

    $payment = Payment::factory()->create([
        'company_id' => $activeCompanyId,
        'customer_id' => $customer->id,
    ]);

    get("/payments/pdf/{$payment->unique_hash}?preview=1&company={$activeCompanyId}", [
        'company' => '',
    ])->assertOk();
});

test('admin can view appointment pdf using company query parameter when header is missing', function () {
    $user = User::query()->firstOrFail();
    $activeCompanyId = (int) $user->companies()->firstOrFail()->id;

    $customer = Customer::factory()->create([
        'company_id' => $activeCompanyId,
    ]);

    $appointment = Appointment::factory()->create([
        'company_id' => $activeCompanyId,
        'customer_id' => $customer->id,
        'creator_id' => $user->id,
    ]);

    get("/appointments/pdf/{$appointment->unique_hash}?preview=1&company={$activeCompanyId}", [
        'company' => '',
    ])->assertOk();
});

test('admin can view customer using company query parameter when header is missing', function () {
    $user = User::query()->firstOrFail();
    $activeCompanyId = (int) $user->companies()->firstOrFail()->id;

    $customer = Customer::factory()->create([
        'company_id' => $activeCompanyId,
    ]);

    getJson("/api/v1/customers/{$customer->id}?company={$activeCompanyId}", [
        'company' => '',
    ])->assertOk();
});

test('admin cannot view customer when active company is missing in header and query', function () {
    $user = User::query()->firstOrFail();
    $activeCompanyId = (int) $user->companies()->firstOrFail()->id;

    $customer = Customer::factory()->create([
        'company_id' => $activeCompanyId,
    ]);

    getJson("/api/v1/customers/{$customer->id}", [
        'company' => '',
    ])->assertForbidden();
});

test('bulk exchange rate update is scoped to the active company', function () {
    $user = User::query()->firstOrFail();
    $activeCompanyId = (int) $user->companies()->firstOrFail()->id;
    $currencyId = (int) CompanySetting::getSetting('currency', $activeCompanyId);

    CompanySetting::setSettings([
        'bulk_exchange_rate_configured' => 'NO',
    ], $activeCompanyId);

    $activeCustomer = Customer::factory()->create([
        'company_id' => $activeCompanyId,
        'currency_id' => $currencyId,
    ]);

    $foreignCompany = Company::factory()->create();
    $foreignCustomer = Customer::factory()->create([
        'company_id' => $foreignCompany->id,
        'currency_id' => $currencyId,
    ]);

    $activeInvoice = Invoice::factory()->create([
        'company_id' => $activeCompanyId,
        'customer_id' => $activeCustomer->id,
        'currency_id' => $currencyId,
        'exchange_rate' => 1,
        'total' => 100,
        'sub_total' => 90,
        'tax' => 10,
        'due_amount' => 100,
        'base_total' => 100,
        'base_sub_total' => 90,
        'base_tax' => 10,
        'base_due_amount' => 100,
        'recurring_invoice_id' => null,
    ]);

    $foreignInvoice = Invoice::factory()->create([
        'company_id' => $foreignCompany->id,
        'customer_id' => $foreignCustomer->id,
        'currency_id' => $currencyId,
        'exchange_rate' => 1,
        'total' => 200,
        'sub_total' => 180,
        'tax' => 20,
        'due_amount' => 200,
        'base_total' => 200,
        'base_sub_total' => 180,
        'base_tax' => 20,
        'base_due_amount' => 200,
        'recurring_invoice_id' => null,
    ]);

    postJson('/api/v1/currencies/bulk-update-exchange-rate', [
        'currencies' => [[
            'id' => $currencyId,
            'exchange_rate' => 2.5,
        ]],
    ])->assertOk();

    $activeInvoice->refresh();
    $foreignInvoice->refresh();

    expect((float) $activeInvoice->exchange_rate)->toBe(2.5)
        ->and((float) $activeInvoice->base_total)->toBe(250.0)
        ->and((float) $foreignInvoice->exchange_rate)->toBe(1.0)
        ->and((float) $foreignInvoice->base_total)->toBe(200.0);
});

test('customer estimate status endpoint rejects unsupported statuses', function () {
    $company = Company::findOrFail(User::query()->firstOrFail()->companies()->firstOrFail()->id);
    $customer = Customer::factory()->create([
        'company_id' => $company->id,
        'enable_portal' => true,
    ]);

    $estimate = Estimate::factory()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'status' => Estimate::STATUS_SENT,
    ]);

    actingAs($customer, 'customer');

    postJson("/api/v1/{$company->slug}/customer/estimate/{$estimate->id}/status", [
        'status' => 'DRAFT',
    ])->assertStatus(422)->assertJsonValidationErrors('status');
});

test('customer estimate status endpoint only acts on sent estimates', function () {
    $company = Company::findOrFail(User::query()->firstOrFail()->companies()->firstOrFail()->id);
    $customer = Customer::factory()->create([
        'company_id' => $company->id,
        'enable_portal' => true,
    ]);

    $estimate = Estimate::factory()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'status' => Estimate::STATUS_DRAFT,
    ]);

    actingAs($customer, 'customer');

    postJson("/api/v1/{$company->slug}/customer/estimate/{$estimate->id}/status", [
        'status' => Estimate::STATUS_ACCEPTED,
    ])->assertNotFound();
});
