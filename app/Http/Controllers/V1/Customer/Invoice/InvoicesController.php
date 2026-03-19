<?php

namespace App\Http\Controllers\V1\Customer\Invoice;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvoicesController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $limit = $request->has('limit') ? $request->limit : 10;

        $invoices = Invoice::with([
            'items',
            'items.taxes',
            'items.fields',
            'items.fields.customField',
            'customer.currency',
            'taxes',
            'fields.customField',
            'company',
            'currency',
        ])
            ->where('status', '<>', 'DRAFT')
            ->applyFilters($request->all())
            ->whereCustomer(Auth::guard('customer')->id())
            ->latest()
            ->paginateData($limit);

        return InvoiceResource::collection($invoices)
            ->additional(['meta' => [
                'invoiceTotalCount' => Invoice::where('status', '<>', 'DRAFT')->whereCustomer(Auth::guard('customer')->id())->count(),
            ]]);
    }

    public function show(Company $company, $id)
    {
        $invoice = $company->invoices()
            ->whereCustomer(Auth::guard('customer')->id())
            ->where('id', $id)
            ->with([
                'items',
                'items.taxes',
                'items.fields',
                'items.fields.customField',
                'customer.currency',
                'taxes',
                'fields.customField',
                'company',
                'currency',
            ])
            ->first();

        if (! $invoice) {
            return response()->json(['error' => 'invoice_not_found'], 404);
        }

        return new InvoiceResource($invoice);
    }
}
