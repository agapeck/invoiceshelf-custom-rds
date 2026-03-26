<?php

namespace App\Http\Controllers\V1\Admin\Invoice;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChangeInvoiceStatusController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request, Invoice $invoice)
    {
        $this->authorize('send invoice', $invoice);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                Invoice::STATUS_SENT,
                Invoice::STATUS_COMPLETED,
            ])],
        ]);

        if ($validated['status'] === Invoice::STATUS_SENT) {
            if ($invoice->status === Invoice::STATUS_COMPLETED) {
                return response()->json([
                    'success' => false,
                    'message' => 'Completed invoices cannot be moved back to sent.',
                ], 422);
            }

            $invoice->status = Invoice::STATUS_SENT;
            $invoice->sent = true;
            $invoice->save();
        } elseif ($validated['status'] === Invoice::STATUS_COMPLETED) {
            $paymentTotal = (float) $invoice->payments()->sum('amount');

            if ((float) $invoice->due_amount > 0.000001 || $paymentTotal + 0.000001 < (float) $invoice->total) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot mark invoice as completed with an outstanding balance or missing payments.',
                ], 422);
            }

            $invoice->status = Invoice::STATUS_COMPLETED;
            $invoice->paid_status = Invoice::STATUS_PAID;
            $invoice->due_amount = 0;
            $invoice->base_due_amount = 0;
            $invoice->save();
        }

        return response()->json([
            'success' => true,
        ]);
    }
}
