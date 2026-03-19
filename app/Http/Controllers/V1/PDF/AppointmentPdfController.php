<?php

namespace App\Http\Controllers\V1\PDF;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;

class AppointmentPdfController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Appointment $appointment)
    {
        if (Auth::guard('customer')->check()) {
            abort_if((int) Auth::guard('customer')->id() !== (int) $appointment->customer_id, 403);
        } else {
            $this->authorize('view', $appointment);
        }

        if ($request->has('preview')) {
            return view('app.pdf.appointment.appointment', compact('appointment'));
        }

        $pdf = Pdf::loadView('app.pdf.appointment.appointment', compact('appointment'));
        return $pdf->stream('appointment-'.$appointment->unique_hash.'.pdf');
    }
}
