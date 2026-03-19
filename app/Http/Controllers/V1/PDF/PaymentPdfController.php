<?php

namespace App\Http\Controllers\V1\PDF;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentPdfController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, Payment $payment)
    {
        if (Auth::guard('customer')->check()) {
            abort_if((int) Auth::guard('customer')->id() !== (int) $payment->customer_id, 403);
        } else {
            $this->authorize('view', $payment);
        }

        if ($request->has('preview')) {
            return $payment->getPDFData();
        }

        return $payment->getGeneratedPDFOrStream('payment');
    }
}
