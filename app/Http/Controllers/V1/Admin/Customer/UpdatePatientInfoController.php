<?php

namespace App\Http\Controllers\V1\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;

class UpdatePatientInfoController extends Controller
{
    /**
     * Update patient information for a customer.
     */
    public function __invoke(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'age' => 'nullable|integer|min:0|max:150',
            'next_of_kin' => 'nullable|string|max:255',
            'next_of_kin_phone' => 'nullable|string|max:255',
            'diagnosis' => 'nullable|string',
            'treatment' => 'nullable|string',
            'attended_to_by' => 'nullable|string|max:255',
            'review_date' => 'nullable|date',
        ]);

        $customer->update($validated);

        $customer->load(['billingAddress', 'shippingAddress', 'fields', 'company', 'currency', 'creator']);

        return new CustomerResource($customer);
    }
}
