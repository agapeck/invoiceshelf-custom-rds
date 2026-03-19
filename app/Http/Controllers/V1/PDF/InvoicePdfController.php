<?php

namespace App\Http\Controllers\V1\PDF;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvoicePdfController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, Invoice $invoice)
    {
        if (Auth::guard('customer')->check()) {
            abort_if((int) Auth::guard('customer')->id() !== (int) $invoice->customer_id, 403);
        } else {
            $this->authorize('view', $invoice);
        }

        if ($request->has('preview')) {
            return $invoice->getPDFData();
        }

        return $invoice->getGeneratedPDFOrStream('invoice');
    }
}
