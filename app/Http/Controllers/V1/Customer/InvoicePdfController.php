<?php

namespace App\Http\Controllers\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\InvoiceResource as CustomerInvoiceResource;
use App\Mail\InvoiceViewedMail;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoicePdfController extends Controller
{
    public function getPdf(EmailLog $emailLog, Request $request)
    {
        $invoice = $this->resolveInvoiceFromEmailLog($emailLog, true);

        if ($invoice->status == Invoice::STATUS_SENT || $invoice->status == Invoice::STATUS_DRAFT) {
            $invoice->status = Invoice::STATUS_VIEWED;
            $invoice->viewed = true;
            $invoice->save();
            $notifyInvoiceViewed = CompanySetting::getSetting(
                'notify_invoice_viewed',
                $invoice->company_id
            );

            if ($notifyInvoiceViewed == 'YES') {
                $data['invoice'] = Invoice::findOrFail($invoice->id)->toArray();
                $customer = Customer::where('company_id', $invoice->company_id)
                    ->find($invoice->customer_id);
                $data['user'] = $customer ? $customer->toArray() : [];
                $notificationEmail = CompanySetting::getSetting(
                    'notification_email',
                    $invoice->company_id
                );

                \Mail::to($notificationEmail)->send(new InvoiceViewedMail($data));
            }
        }

        if ($request->has('pdf')) {
            return $invoice->getGeneratedPDFOrStream('invoice');
        }

        return view('app')->with([
            'customer_logo' => get_company_setting('customer_portal_logo', $invoice->company_id),
            'current_theme' => get_company_setting('customer_portal_theme', $invoice->company_id),
        ]);
    }

    public function getInvoice(EmailLog $emailLog)
    {
        $this->resolveInvoiceFromEmailLog($emailLog, true);

        $invoice = Invoice::with([
            'items',
            'items.taxes',
            'items.fields',
            'items.fields.customField',
            'customer.currency',
            'taxes',
            'fields.customField',
            'company',
            'currency',
        ])->find($emailLog->mailable_id);

        return new CustomerInvoiceResource($invoice);
    }

    private function resolveInvoiceFromEmailLog(EmailLog $emailLog, bool $enforceExpiry): Invoice
    {
        abort_if($emailLog->mailable_type !== Invoice::class, 404);
        abort_if($enforceExpiry && $emailLog->isExpired(), 403, 'Link Expired.');

        $invoice = $emailLog->mailable;
        abort_if(! $invoice instanceof Invoice, 404);

        return $invoice;
    }
}
