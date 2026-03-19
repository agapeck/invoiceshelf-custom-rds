<?php

namespace App\Http\Controllers\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\EmailLog;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentPdfController extends Controller
{
    public function getPdf(EmailLog $emailLog, Request $request)
    {
        $payment = $this->resolvePaymentFromEmailLog($emailLog, true);

        return $payment->getGeneratedPDFOrStream('payment');
    }

    public function getPayment(EmailLog $emailLog)
    {
        $this->resolvePaymentFromEmailLog($emailLog, true);

        $payment = Payment::with([
            'customer.currency',
            'invoice',
            'paymentMethod',
            'fields',
            'fields.customField',
            'company',
            'currency',
            'transaction',
        ])->find($emailLog->mailable_id);

        return new PaymentResource($payment);
    }

    private function resolvePaymentFromEmailLog(EmailLog $emailLog, bool $enforceExpiry): Payment
    {
        abort_if($emailLog->mailable_type !== Payment::class, 404);
        abort_if($enforceExpiry && $emailLog->isExpired(), 403, 'Link Expired.');

        $payment = $emailLog->mailable;
        abort_if(! $payment instanceof Payment, 404);

        return $payment;
    }
}
