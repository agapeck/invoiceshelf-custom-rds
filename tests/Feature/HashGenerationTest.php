<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Vinkla\Hashids\Facades\Hashids;
use Illuminate\Support\Facades\Artisan;

class HashGenerationTest extends TestCase
{
    use DatabaseTransactions;

    protected $company;
    protected $user;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Create or find test data
        $this->company = Company::factory()->create();
        
        // Try to use admin user (id 1) to avoid permission issues, otherwise create one
        $this->user = User::find(1);
        if (!$this->user) {
             $this->user = User::factory()->create();
        }
        
        // ensure user is super admin to bypass all checks
        $this->user->role = 'super admin';
        $this->user->save();
        
        \Silber\Bouncer\BouncerFacade::allow($this->user)->everything();
        
        // Attach user to company if not already attached
        if (!$this->user->companies()->where('company_id', $this->company->id)->exists()) {
             $this->user->companies()->attach($this->company->id);
        }

        $this->customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);
        
        $this->actingAs($this->user, 'sanctum');
        $this->withHeaders(['company' => $this->company->id]);
        
        // Ensure Currency exists
        if (! \App\Models\Currency::find(1)) {
            \App\Models\Currency::factory()->create(['id' => 1]);
        }
        
        // Seed necessary settings
        \App\Models\CompanySetting::updateOrCreate(
            ['company_id' => $this->company->id, 'option' => 'carbon_date_format'],
            ['value' => 'Y-m-d']
        );
        \App\Models\CompanySetting::updateOrCreate(
            ['company_id' => $this->company->id, 'option' => 'fiscal_year'],
            ['value' => '1-12']
        );
        \App\Models\CompanySetting::updateOrCreate(
            ['company_id' => $this->company->id, 'option' => 'invoice_number_format'],
            ['value' => 'INV-{$number}']
        );
        \App\Models\CompanySetting::updateOrCreate(
            ['company_id' => $this->company->id, 'option' => 'estimate_number_format'],
            ['value' => 'EST-{$number}']
        );
    }

    /**
     * Test Invoice hash generation
     */
    public function test_invoice_generates_hash_on_create()
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'unique_hash' => null, // Force trait to generate hash
            'invoice_number' => 'TEST-INV-' . uniqid(),
        ]);

        $this->assertNotNull($invoice->unique_hash);
        $this->assertEquals(
            Hashids::connection(Invoice::class)->encode($invoice->id),
            $invoice->unique_hash
        );
    }

    /**
     * Test Payment hash generation
     */
    public function test_payment_generates_hash_on_create()
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
        ]);

        $payment = Payment::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'unique_hash' => null, // Force trait to generate hash
            'payment_number' => 'TEST-PAY-' . uniqid(),
        ]);

        $this->assertNotNull($payment->unique_hash);
        $this->assertEquals(
            Hashids::connection(Payment::class)->encode($payment->id),
            $payment->unique_hash
        );
    }

    /**
     * Test Estimate hash generation
     */
    public function test_estimate_generates_hash_on_create()
    {
        $estimate = Estimate::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'unique_hash' => null, // Force trait to generate hash
            'estimate_number' => 'TEST-EST-' . uniqid(),
        ]);

        $this->assertNotNull($estimate->unique_hash);
        $this->assertEquals(
            Hashids::connection(Estimate::class)->encode($estimate->id),
            $estimate->unique_hash
        );
    }

    /**
     * Test Transaction hash generation
     */
    public function test_transaction_generates_hash_on_create()
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
        ]);

        $transaction = Transaction::factory()->create([
            'company_id' => $this->company->id,
            'invoice_id' => $invoice->id,
            'unique_hash' => null, // Force trait to generate hash
        ]);

        // Note: Transaction factory might not trigger the static create method but define()
        // But our trait hooks into 'created' event, so it should work regardless
        $this->assertNotNull($transaction->unique_hash);
        $this->assertEquals(
            Hashids::connection(Transaction::class)->encode($transaction->id),
            $transaction->unique_hash
        );
    }

    /**
     * Test Appointment hash generation
     */
    public function test_appointment_generates_hash_on_create()
    {
        $appointment = Appointment::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'unique_hash' => null, // Force trait to generate hash
        ]);

        $this->assertNotNull($appointment->unique_hash);
        $this->assertEquals(
            Hashids::connection(Appointment::class)->encode($appointment->id),
            $appointment->unique_hash
        );
    }

    /**
     * Test Hash Regeneration Command
     */
    public function test_hash_regeneration_command()
    {
        // 1. Create entities with valid hashes first (via factory which triggers trait)
        $invoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'TEST-INV-REG-' . uniqid(),
        ]);
        
        $payment = Payment::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'payment_number' => 'PAY-TEST-' . uniqid(),
            'amount' => 100,
        ]);

        // 2. Forcefully null out the hashes using saveQuietly to bypass events
        $invoice->unique_hash = null;
        $invoice->saveQuietly();
        
        $payment->unique_hash = null;
        $payment->saveQuietly();

        // Verify hashes are actually null (fresh query)
        $this->assertNull(Invoice::find($invoice->id)->unique_hash, 'Invoice hash should be null before regeneration');
        $this->assertNull(Payment::find($payment->id)->unique_hash, 'Payment hash should be null before regeneration');

        // 3. Directly call trait's static method (avoid Artisan command isolation issues)
        $invoiceResults = Invoice::regenerateMissingHashes();
        $paymentResults = Payment::regenerateMissingHashes();

        // Verify some records were processed
        $this->assertGreaterThanOrEqual(1, $invoiceResults['success'], 'At least 1 invoice hash should be regenerated');
        $this->assertGreaterThanOrEqual(1, $paymentResults['success'], 'At least 1 payment hash should be regenerated');

        // 4. Verify Hashes were regenerated
        $invoice->refresh();
        $payment->refresh();

        $this->assertNotNull($invoice->unique_hash, 'Invoice hash should be regenerated');
        $this->assertNotNull($payment->unique_hash, 'Payment hash should be regenerated');
        
        $this->assertEquals(
            Hashids::connection(Invoice::class)->encode($invoice->id),
            $invoice->unique_hash,
            'Invoice hash should match expected encoding'
        );
        $this->assertEquals(
            Hashids::connection(Payment::class)->encode($payment->id),
            $payment->unique_hash,
            'Payment hash should match expected encoding'
        );
    }

    /**
     * Test Clone Invoice generates new hash
     */
    public function test_clone_invoice_generates_new_hash()
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
        ]);
        
        $originalHash = $invoice->unique_hash;

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/clone");
        
        $response->assertStatus(201);
        
        $newInvoiceId = $response->json('data.id');
        $newInvoice = Invoice::find($newInvoiceId);
        
        $this->assertNotNull($newInvoice->unique_hash);
        $this->assertNotEquals($originalHash, $newInvoice->unique_hash);
        $this->assertEquals(
            Hashids::connection(Invoice::class)->encode($newInvoice->id),
            $newInvoice->unique_hash
        );
    }

    /**
     * Test Clone Estimate generates new hash
     */
    public function test_clone_estimate_generates_new_hash()
    {
        $estimate = Estimate::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'unique_hash' => null,
            'estimate_number' => 'TEST-EST-CLO-' . uniqid(),
        ]);
        
        $originalHash = $estimate->unique_hash;

        $response = $this->postJson("/api/v1/estimates/{$estimate->id}/clone");
        
        $response->assertStatus(201);
        
        $newEstimateId = $response->json('data.id');
        $newEstimate = Estimate::find($newEstimateId);
        
        $this->assertNotNull($newEstimate->unique_hash);
        $this->assertNotEquals($originalHash, $newEstimate->unique_hash);
        $this->assertEquals(
            Hashids::connection(Estimate::class)->encode($newEstimate->id),
            $newEstimate->unique_hash
        );
    }

    /**
     * Test Convert Estimate generates new invoice hash
     */
    public function test_convert_estimate_generates_new_invoice_hash()
    {
        $estimate = Estimate::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'unique_hash' => null,
            'estimate_number' => 'TEST-EST-CON-' . uniqid(),
        ]);
        
        $response = $this->postJson("/api/v1/estimates/{$estimate->id}/convert-to-invoice");
        
        $response->assertOk();
        
        $invoiceId = $response->json('data.id');
        $invoice = Invoice::find($invoiceId);
        
        $this->assertNotNull($invoice->unique_hash);
        $this->assertEquals(
            Hashids::connection(Invoice::class)->encode($invoice->id),
            $invoice->unique_hash
        );
    }
}
