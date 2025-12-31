<?php

namespace App\Http\Controllers\V1\Admin\Report;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use PDF;

class DentistSalesReportController extends Controller
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

        $locale = CompanySetting::getSetting('language', $company->id);

        App::setLocale($locale);

        $start = Carbon::createFromFormat('Y-m-d', $request->from_date);
        $end = Carbon::createFromFormat('Y-m-d', $request->to_date);

        // Get invoices with eager loaded relationships
        $invoices = Invoice::where('company_id', $company->id)
            ->whereNotNull('assigned_to_id')
            ->whereBetween('invoice_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->with(['assignedTo', 'customer'])
            ->get();

        // Group invoices by dentist and calculate totals
        $dentistSales = [];
        $totalAmount = 0;

        foreach ($invoices as $invoice) {
            $dentistId = $invoice->assigned_to_id;
            
            if (!isset($dentistSales[$dentistId])) {
                $dentist = $invoice->assignedTo; // Use eager loaded relationship
                $dentistSales[$dentistId] = [
                    'dentist' => $dentist,
                    'name' => $dentist?->name ?? 'Unknown',
                    'invoices' => [],
                    'totalAmount' => 0,
                    'invoiceCount' => 0,
                ];
            }

            $dentistSales[$dentistId]['invoices'][] = $invoice;
            $dentistSales[$dentistId]['totalAmount'] += $invoice->base_total;
            $dentistSales[$dentistId]['invoiceCount']++;
            $totalAmount += $invoice->base_total;
        }

        // Sort by total amount descending
        usort($dentistSales, fn($a, $b) => $b['totalAmount'] <=> $a['totalAmount']);

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
            'dentistSales' => $dentistSales,
            'totalAmount' => $totalAmount,
            'colorSettings' => $colorSettings,
            'company' => $company,
            'from_date' => $from_date,
            'to_date' => $to_date,
            'currency' => $currency,
        ]);

        $pdf = PDF::loadView('app.pdf.reports.sales-dentists');

        if ($request->has('preview')) {
            return view('app.pdf.reports.sales-dentists');
        }

        if ($request->has('download')) {
            return $pdf->download();
        }

        return $pdf->stream();
    }
}
