<?php

namespace App\Http\Controllers\V1\Admin\Estimate;

use App\Http\Controllers\Controller;
use App\Models\Estimate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChangeEstimateStatusController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, Estimate $estimate)
    {
        $this->authorize('send estimate', $estimate);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                Estimate::STATUS_DRAFT,
                Estimate::STATUS_SENT,
                Estimate::STATUS_VIEWED,
                Estimate::STATUS_EXPIRED,
                Estimate::STATUS_ACCEPTED,
                Estimate::STATUS_REJECTED,
            ])],
        ]);

        $estimate->update($validated);

        return response()->json([
            'success' => true,
        ]);
    }
}
