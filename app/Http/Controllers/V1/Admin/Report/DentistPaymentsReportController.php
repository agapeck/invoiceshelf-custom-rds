<?php

namespace App\Http\Controllers\V1\Admin\Report;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use PDF;

class DentistPaymentsReportController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  string  $hash
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, $hash)
    {
        $company = Company::where('unique_hash', $hash)->first();

        $this->authorize('view report', $company);

        $request->validate([
            'from_date' => 'required|date_format:Y-m-d',
            'to_date' => 'required|date_format:Y-m-d|after_or_equal:from_date',
        ]);

        $locale = CompanySetting::getSetting('language', $company->id);

        App::setLocale($locale);

        $start = Carbon::createFromFormat('Y-m-d', $request->from_date);
        $end = Carbon::createFromFormat('Y-m-d', $request->to_date);

        // Get payments with invoices that have a dentist assigned
        $payments = Payment::where('company_id', $company->id)
            ->whereHas('invoice', function($query) {
                $query->whereNotNull('assigned_to_id');
            })
            ->whereBetween('payment_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->with(['invoice.assignedTo', 'customer'])
            ->get();

        // Group payments by dentist and calculate totals
        $dentistPayments = [];
        $totalAmount = 0;

        foreach ($payments as $payment) {
            $dentistId = $payment->invoice->assigned_to_id;
            
            if (!isset($dentistPayments[$dentistId])) {
                $dentist = $payment->invoice->assignedTo;
                $dentistPayments[$dentistId] = [
                    'dentist' => $dentist,
                    'name' => $dentist?->name ?? 'Unknown',
                    'payments' => [],
                    'totalAmount' => 0,
                    'paymentCount' => 0,
                ];
            }

            $dentistPayments[$dentistId]['payments'][] = $payment;
            $dentistPayments[$dentistId]['totalAmount'] += $payment->base_amount;
            $dentistPayments[$dentistId]['paymentCount']++;
            $totalAmount += $payment->base_amount;
        }

        // Sort by total amount descending
        usort($dentistPayments, fn($a, $b) => $b['totalAmount'] <=> $a['totalAmount']);

        $dateFormat = CompanySetting::getSetting('carbon_date_format', $company->id);
        $from_date = Carbon::createFromFormat('Y-m-d', $request->from_date)->translatedFormat($dateFormat);
        $to_date = Carbon::createFromFormat('Y-m-d', $request->to_date)->translatedFormat($dateFormat);
        $currency = Currency::findOrFail(CompanySetting::getSetting('currency', $company->id));

        $colors = [
            'primary_text_color',
            'heading_text_color',
            'section_heading_text_color',
            'border_color',
            'body_text_color',
            'footer_text_color',
            'footer_total_color',
            'footer_bg_color',
            'date_text_color',
        ];

        $colorSettings = CompanySetting::whereIn('option', $colors)
            ->whereCompany($company->id)
            ->get();

        view()->share([
            'dentistPayments' => $dentistPayments,
            'totalAmount' => $totalAmount,
            'colorSettings' => $colorSettings,
            'company' => $company,
            'from_date' => $from_date,
            'to_date' => $to_date,
            'currency' => $currency,
        ]);

        $pdf = PDF::loadView('app.pdf.reports.payments-dentists');

        if ($request->has('preview')) {
            return view('app.pdf.reports.payments-dentists');
        }

        if ($request->has('download')) {
            return $pdf->download();
        }

        return $pdf->stream();
    }
}
