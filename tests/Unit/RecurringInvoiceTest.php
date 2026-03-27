<?php

use App\Models\InvoiceItem;
use App\Models\RecurringInvoice;
use App\Models\Tax;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
});

test('recurring invoice has many invoices', function () {
    $recurringInvoice = RecurringInvoice::factory()->hasInvoices(5)->create();

    $this->assertCount(5, $recurringInvoice->invoices);

    $this->assertTrue($recurringInvoice->invoices()->exists());
});

test('recurring invoice has many invoice items', function () {
    $recurringInvoice = RecurringInvoice::factory()->hasItems(5)->create();

    $this->assertCount(5, $recurringInvoice->items);

    $this->assertTrue($recurringInvoice->items()->exists());
});

test('recurring invoice has many taxes', function () {
    $recurringInvoice = RecurringInvoice::factory()->hasTaxes(5)->create();

    $this->assertCount(5, $recurringInvoice->taxes);

    $this->assertTrue($recurringInvoice->taxes()->exists());
});

test('recurring invoice belongs to customer', function () {
    $recurringInvoice = RecurringInvoice::factory()->forCustomer()->create();

    $this->assertTrue($recurringInvoice->customer()->exists());
});

test('force deleting recurring invoice removes related items and taxes', function () {
    $recurringInvoice = RecurringInvoice::factory()->create();

    $item = InvoiceItem::factory()->create([
        'recurring_invoice_id' => $recurringInvoice->id,
        'company_id' => $recurringInvoice->company_id,
    ]);

    $tax = Tax::factory()->create([
        'recurring_invoice_id' => $recurringInvoice->id,
        'company_id' => $recurringInvoice->company_id,
        'currency_id' => $recurringInvoice->currency_id,
    ]);

    $recurringInvoice->forceDelete();

    $this->assertDatabaseMissing('recurring_invoices', ['id' => $recurringInvoice->id]);
    $this->assertModelMissing($item);
    $this->assertModelMissing($tax);
});

test('soft deleting recurring invoice preserves related items and taxes', function () {
    $recurringInvoice = RecurringInvoice::factory()->create();

    $item = InvoiceItem::factory()->create([
        'recurring_invoice_id' => $recurringInvoice->id,
        'company_id' => $recurringInvoice->company_id,
    ]);

    $tax = Tax::factory()->create([
        'recurring_invoice_id' => $recurringInvoice->id,
        'company_id' => $recurringInvoice->company_id,
        'currency_id' => $recurringInvoice->currency_id,
    ]);

    $recurringInvoice->delete();

    $this->assertSoftDeleted('recurring_invoices', ['id' => $recurringInvoice->id]);
    $this->assertDatabaseHas('invoice_items', ['id' => $item->id]);
    $this->assertDatabaseHas('taxes', ['id' => $tax->id]);
});
