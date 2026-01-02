<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Estimate;
use App\Services\SerialNumberFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialNumberFormatterTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $customer;
    protected $currency;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->company = Company::factory()->create();
        $this->currency = Currency::factory()->create();
        $this->customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'currency_id' => $this->currency->id,
        ]);
        
        CompanySetting::setSettings([
            'invoice_number_format' => 'INV-{{SEQUENCE:6}}',
            'payment_number_format' => 'PAY-{{SEQUENCE:6}}',
            'estimate_number_format' => 'EST-{{SEQUENCE:6}}',
            'currency' => $this->currency->id,
        ], $this->company->id);
    }

    /**
     * Test that SerialNumberFormatter skips soft-deleted invoice numbers
     */
    public function test_skips_soft_deleted_invoice_numbers()
    {
        // Create and soft-delete an invoice
        $deletedInvoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-000001',
            'sequence_number' => 1,
            'customer_sequence_number' => 1,
            'currency_id' => $this->currency->id,
        ]);
        $deletedInvoice->delete(); // Soft delete
        
        // Verify it's soft-deleted
        $this->assertSoftDeleted('invoices', ['id' => $deletedInvoice->id]);
        
        // Get next number - should skip the deleted one
        $serial = (new SerialNumberFormatter)
            ->setModel(Invoice::class)
            ->setCompany($this->company->id)
            ->setCustomer($this->customer->id)
            ->setNextNumbers();
            
        $nextNumber = $serial->getNextNumber();
        
        // Should be 2, not 1 (which is soft-deleted)
        $this->assertEquals(2, $serial->nextSequenceNumber);
        $this->assertEquals('INV-000002', $nextNumber);
    }

    /**
     * Test that SerialNumberFormatter skips soft-deleted payment numbers
     */
    public function test_skips_soft_deleted_payment_numbers()
    {
        // Create and soft-delete a payment
        $deletedPayment = Payment::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'payment_number' => 'PAY-000001',
            'sequence_number' => 1,
            'customer_sequence_number' => 1,
            'currency_id' => $this->currency->id,
        ]);
        $deletedPayment->delete();
        
        $this->assertSoftDeleted('payments', ['id' => $deletedPayment->id]);
        
        $serial = (new SerialNumberFormatter)
            ->setModel(Payment::class)
            ->setCompany($this->company->id)
            ->setCustomer($this->customer->id)
            ->setNextNumbers();
            
        $nextNumber = $serial->getNextNumber();
        
        $this->assertEquals(2, $serial->nextSequenceNumber);
        $this->assertEquals('PAY-000002', $nextNumber);
    }

    /**
     * Test that SerialNumberFormatter skips soft-deleted estimate numbers
     */
    public function test_skips_soft_deleted_estimate_numbers()
    {
        $deletedEstimate = Estimate::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'estimate_number' => 'EST-000001',
            'sequence_number' => 1,
            'customer_sequence_number' => 1,
            'currency_id' => $this->currency->id,
        ]);
        $deletedEstimate->delete();
        
        $this->assertSoftDeleted('estimates', ['id' => $deletedEstimate->id]);
        
        $serial = (new SerialNumberFormatter)
            ->setModel(Estimate::class)
            ->setCompany($this->company->id)
            ->setCustomer($this->customer->id)
            ->setNextNumbers();
            
        $nextNumber = $serial->getNextNumber();
        
        $this->assertEquals(2, $serial->nextSequenceNumber);
        $this->assertEquals('EST-000002', $nextNumber);
    }

    /**
     * Test that active (non-deleted) records still work correctly
     */
    public function test_works_with_active_records()
    {
        // Create an active invoice
        Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-000001',
            'sequence_number' => 1,
            'customer_sequence_number' => 1,
            'currency_id' => $this->currency->id,
        ]);
        
        $serial = (new SerialNumberFormatter)
            ->setModel(Invoice::class)
            ->setCompany($this->company->id)
            ->setCustomer($this->customer->id)
            ->setNextNumbers();
            
        $nextNumber = $serial->getNextNumber();
        
        $this->assertEquals(2, $serial->nextSequenceNumber);
        $this->assertEquals('INV-000002', $nextNumber);
    }

    /**
     * Test mixed scenario: some deleted, some active
     */
    public function test_handles_mixed_deleted_and_active_records()
    {
        // Create sequence: 1 (active), 2 (deleted), 3 (deleted)
        Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-000001',
            'sequence_number' => 1,
            'customer_sequence_number' => 1,
            'currency_id' => $this->currency->id,
        ]);
        
        $deleted2 = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-000002',
            'sequence_number' => 2,
            'customer_sequence_number' => 2,
            'currency_id' => $this->currency->id,
        ]);
        $deleted2->delete();
        
        $deleted3 = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-000003',
            'sequence_number' => 3,
            'customer_sequence_number' => 3,
            'currency_id' => $this->currency->id,
        ]);
        $deleted3->delete();
        
        $serial = (new SerialNumberFormatter)
            ->setModel(Invoice::class)
            ->setCompany($this->company->id)
            ->setCustomer($this->customer->id)
            ->setNextNumbers();
            
        $nextNumber = $serial->getNextNumber();
        
        // Should be 4 (after highest sequence including deleted)
        $this->assertEquals(4, $serial->nextSequenceNumber);
        $this->assertEquals('INV-000004', $nextNumber);
    }

    /**
     * Test that first record works when no records exist
     */
    public function test_first_record_when_empty()
    {
        $serial = (new SerialNumberFormatter)
            ->setModel(Invoice::class)
            ->setCompany($this->company->id)
            ->setCustomer($this->customer->id)
            ->setNextNumbers();
            
        $nextNumber = $serial->getNextNumber();
        
        $this->assertEquals(1, $serial->nextSequenceNumber);
        $this->assertEquals('INV-000001', $nextNumber);
    }

    /**
     * Test company isolation - deleted records from other companies shouldn't affect
     */
    public function test_company_isolation()
    {
        $otherCompany = Company::factory()->create();
        
        // Create deleted invoice in OTHER company
        $otherInvoice = Invoice::factory()->create([
            'company_id' => $otherCompany->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-000001',
            'sequence_number' => 1,
            'customer_sequence_number' => 1,
            'currency_id' => $this->currency->id,
        ]);
        $otherInvoice->delete();
        
        // Get next for THIS company - should still be 1
        $serial = (new SerialNumberFormatter)
            ->setModel(Invoice::class)
            ->setCompany($this->company->id)
            ->setCustomer($this->customer->id)
            ->setNextNumbers();
            
        $nextNumber = $serial->getNextNumber();
        
        $this->assertEquals(1, $serial->nextSequenceNumber);
        $this->assertEquals('INV-000001', $nextNumber);
    }
}
