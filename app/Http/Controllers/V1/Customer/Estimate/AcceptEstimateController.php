<?php

namespace App\Http\Controllers\V1\Customer\Estimate;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\EstimateResource;
use App\Models\Company;
use App\Models\Estimate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AcceptEstimateController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  Estimate  $estimate
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, Company $company, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                Estimate::STATUS_ACCEPTED,
                Estimate::STATUS_REJECTED,
            ])],
        ]);

        $estimate = $company->estimates()
            ->whereCustomer(Auth::guard('customer')->id())
            ->where('id', $id)
            ->where('status', Estimate::STATUS_SENT)
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

        if (! $estimate) {
            return response()->json(['error' => 'estimate_not_found'], 404);
        }

        $estimate->update($validated);

        return new EstimateResource($estimate->fresh([
            'items',
            'items.taxes',
            'items.fields',
            'items.fields.customField',
            'customer.currency',
            'taxes',
            'fields.customField',
            'company',
            'currency',
        ]));
    }
}
