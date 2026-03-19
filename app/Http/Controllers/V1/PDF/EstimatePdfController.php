<?php

namespace App\Http\Controllers\V1\PDF;

use App\Http\Controllers\Controller;
use App\Models\Estimate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EstimatePdfController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, Estimate $estimate)
    {
        if (Auth::guard('customer')->check()) {
            abort_if((int) Auth::guard('customer')->id() !== (int) $estimate->customer_id, 403);
        } else {
            $this->authorize('view', $estimate);
        }

        if ($request->has('preview')) {
            return $estimate->getPDFData();
        }

        return $estimate->getGeneratedPDFOrStream('estimate');
    }
}
