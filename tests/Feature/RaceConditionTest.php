<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\Estimate\ConvertEstimateController;
use App\Http\Requests\InvoicesRequest;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RaceConditionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure we have proper test environment
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);
    }

    public function test_invoice_creation_handles_duplicate_number_gracefully()
    {
        // 1. Setup
        $user = User::factory()->createOne(['role' => 'super admin']);
        $company = Company::factory()->create();
        $user->companies()->attach($company->id);
        $currency = Currency::factory()->create();
        $customer = Customer::factory()->create([
            'company_id' => $company->id,
            'currency_id' => $currency->id,
        ]);

        // Set settings
        CompanySetting::setSettings([
            'invoice_number_format' => 'INV-{{SEQUENCE}}',
            'currency' => $currency->id,
        ], $company->id);

        // 2. Create an existing invoice with a specific number
        $existingInvoice = Invoice::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-000001',
            'sequence_number' => 1,
            'customer_sequence_number' => 1,
            'currency_id' => $currency->id,
        ]);

        // 3. Verify the existing invoice is in the database
        $this->assertDatabaseHas('invoices', [
            'id' => $existingInvoice->id,
            'invoice_number' => 'INV-000001',
        ]);

        // 4. Create a second invoice - it should get the next number
        $requestData = [
            'invoice_date' => '2025-01-01',
            'due_date' => '2025-01-15',
            'customer_id' => $customer->id,
            'items' => [
                [
                    'name' => 'Test Item',
                    'description' => 'Test description',
                    'quantity' => 1,
                    'price' => 10000,
                    'discount_type' => 'fixed',
                    'discount' => 0,
                    'discount_val' => 0,
                    'tax' => 0,
                    'total' => 10000,
                    'unit_name' => 'unit',
                ]
            ],
            'sub_total' => 10000,
            'total' => 10000,
            'tax' => 0,
            'discount' => 0,
            'discount_type' => 'fixed',
            'discount_val' => 0,
            'template_name' => 'invoice1',
            'notes' => '',
        ];

        $request = new InvoicesRequest();
        $request->setMethod('POST');
        $request->headers->set('company', $company->id);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });
        $request->merge($requestData);

        // 5. Call createInvoice - it should succeed with a non-colliding number
        $newInvoice = Invoice::createInvoice($request);

        // 6. Assertions
        $this->assertNotNull($newInvoice);
        $this->assertNotEquals('INV-000001', $newInvoice->invoice_number);
        
        // The new invoice should have a different invoice number
        $this->assertDatabaseHas('invoices', [
            'id' => $newInvoice->id,
        ]);
        
        // Verify sequence_number is consistent (not 1, since 1 is taken)
        $this->assertGreaterThan(1, $newInvoice->sequence_number);
    }

    public function test_invoice_sequence_numbers_are_consistent_with_invoice_number()
    {
        // Setup
        $user = User::factory()->create(['role' => 'super admin']);
        $company = Company::factory()->create();
        $user->companies()->attach($company->id);
        $currency = Currency::factory()->create();
        $customer = Customer::factory()->create([
            'company_id' => $company->id,
            'currency_id' => $currency->id,
        ]);

        CompanySetting::setSettings([
            'invoice_number_format' => 'INV-{{SEQUENCE}}',
            'currency' => $currency->id,
        ], $company->id);

        // Create request
        $requestData = [
            'invoice_date' => '2025-01-01',
            'due_date' => '2025-01-15',
            'customer_id' => $customer->id,
            'items' => [
                [
                    'name' => 'Test Item',
                    'description' => 'Test description',
                    'quantity' => 1,
                    'price' => 10000,
                    'discount_type' => 'fixed',
                    'discount' => 0,
                    'discount_val' => 0,
                    'tax' => 0,
                    'total' => 10000,
                    'unit_name' => 'unit',
                ]
            ],
            'sub_total' => 10000,
            'total' => 10000,
            'tax' => 0,
            'discount' => 0,
            'discount_type' => 'fixed',
            'discount_val' => 0,
            'template_name' => 'invoice1',
            'notes' => '',
        ];

        $request = new InvoicesRequest();
        $request->setMethod('POST');
        $request->headers->set('company', $company->id);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });
        $request->merge($requestData);

        // Create first invoice
        $invoice1 = Invoice::createInvoice($request);
        
        // Verify sequence_number matches what's in invoice_number
        $expectedSeq1 = intval(preg_replace('/[^0-9]/', '', $invoice1->invoice_number));
        $this->assertEquals($expectedSeq1, $invoice1->sequence_number);

        // Create second invoice
        $request2 = new InvoicesRequest();
        $request2->setMethod('POST');
        $request2->headers->set('company', $company->id);
        $request2->setUserResolver(function () use ($user) {
            return $user;
        });
        $request2->merge($requestData);
        
        $invoice2 = Invoice::createInvoice($request2);
        
        // Verify sequence_number matches what's in invoice_number
        $expectedSeq2 = intval(preg_replace('/[^0-9]/', '', $invoice2->invoice_number));
        $this->assertEquals($expectedSeq2, $invoice2->sequence_number);
        
        // Verify they are sequential
        $this->assertGreaterThan($invoice1->sequence_number, $invoice2->sequence_number);
    }

    public function test_estimate_conversion_uses_company_invoice_lock()
    {
        $user = User::factory()->create(['role' => 'super admin']);
        $company = Company::factory()->create();
        $user->companies()->attach($company->id);

        $currency = Currency::factory()->create();
        $customer = Customer::factory()->create([
            'company_id' => $company->id,
            'currency_id' => $currency->id,
        ]);

        CompanySetting::setSettings([
            'invoice_number_format' => 'INV-{{SEQUENCE}}',
            'estimate_number_format' => 'EST-{{SEQUENCE}}',
            'currency' => $currency->id,
        ], $company->id);

        $estimate = Estimate::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'currency_id' => $currency->id,
        ]);

        $lock = \Mockery::mock();
        $lock->shouldReceive('block')
            ->once()
            ->with(5, \Mockery::type(\Closure::class))
            ->andReturnUsing(function ($seconds, $callback) {
                return $callback();
            });

        Cache::shouldReceive('lock')
            ->once()
            ->with("invoice-number:{$company->id}", 10)
            ->andReturn($lock);

        $this->actingAs($user);

        $request = Request::create('/api/v1/estimates/'.$estimate->id.'/convert-to-invoice', 'POST');
        $request->headers->set('company', $company->id);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        app(ConvertEstimateController::class)->__invoke($request, $estimate);

        $this->assertTrue(Invoice::where('company_id', $company->id)->exists());
    }

    public function test_recurring_invoice_generation_fails_when_lock_cannot_be_acquired()
    {
        $user = User::factory()->createOne(['role' => 'super admin']);
        $company = Company::factory()->create();
        $user->companies()->attach($company->id);

        $currency = Currency::factory()->create();
        $customer = Customer::factory()->create([
            'company_id' => $company->id,
            'currency_id' => $currency->id,
        ]);

        CompanySetting::setSettings([
            'invoice_number_format' => 'INV-{{SEQUENCE}}',
            'currency' => $currency->id,
        ], $company->id);

        $recurringInvoice = RecurringInvoice::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'status' => RecurringInvoice::ACTIVE,
            'limit_by' => RecurringInvoice::NONE,
        ]);

        $lock = \Mockery::mock();
        $lock->shouldReceive('block')
            ->once()
            ->with(5, \Mockery::type(\Closure::class))
            ->andThrow(new LockTimeoutException());

        Cache::shouldReceive('lock')
            ->once()
            ->with("invoice-number:{$company->id}", 10)
            ->andReturn($lock);

        $this->expectException(LockTimeoutException::class);

        $recurringInvoice->createInvoice();
    }
}
