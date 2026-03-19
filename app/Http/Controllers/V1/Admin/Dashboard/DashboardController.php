<?php

namespace App\Http\Controllers\V1\Admin\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request)
    {
        $company = Company::find($request->header('company'));

        $this->authorize('view dashboard', $company);

        $months = [];
        $monthKeys = [];
        $fiscalYear = CompanySetting::getSetting('fiscal_year', $request->header('company'));
        $startDate = Carbon::now();
        $start = Carbon::now();
        $end = Carbon::now();
        $terms = explode('-', $fiscalYear);
        $companyStartMonth = intval($terms[0]);

        if ($companyStartMonth <= $start->month) {
            $startDate->month($companyStartMonth)->startOfMonth();
            $start->month($companyStartMonth)->startOfMonth();
            $end->month($companyStartMonth)->endOfMonth();
        } else {
            $startDate->subYear()->month($companyStartMonth)->startOfMonth();
            $start->subYear()->month($companyStartMonth)->startOfMonth();
            $end->subYear()->month($companyStartMonth)->endOfMonth();
        }

        if ($request->has('previous_year')) {
            $startDate->subYear()->startOfMonth();
            $start->subYear()->startOfMonth();
            $end->subYear()->endOfMonth();
        }

        $periodStart = $start->copy()->startOfMonth();
        $periodEnd = $periodStart->copy()->addMonths(11)->endOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $monthDate = $periodStart->copy()->addMonths($i);
            $monthKeys[] = $monthDate->format('Y-m');
            $months[] = $monthDate->translatedFormat('M');
        }

        $invoiceMonthly = $this->getMonthlySums(
            Invoice::whereCompany()->whereBetween('invoice_date', [$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]),
            'invoice_date',
            'base_total'
        );
        $expenseMonthly = $this->getMonthlySums(
            Expense::whereCompany()->whereBetween('expense_date', [$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]),
            'expense_date',
            'base_amount'
        );
        $paymentMonthly = $this->getMonthlySums(
            Payment::whereCompany()->whereBetween('payment_date', [$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]),
            'payment_date',
            'base_amount'
        );

        $invoice_totals = [];
        $expense_totals = [];
        $receipt_totals = [];
        $net_income_totals = [];

        foreach ($monthKeys as $monthKey) {
            $invoiceTotal = (float) ($invoiceMonthly[$monthKey] ?? 0);
            $expenseTotal = (float) ($expenseMonthly[$monthKey] ?? 0);
            $receiptTotal = (float) ($paymentMonthly[$monthKey] ?? 0);

            $invoice_totals[] = $invoiceTotal;
            $expense_totals[] = $expenseTotal;
            $receipt_totals[] = $receiptTotal;
            $net_income_totals[] = $receiptTotal - $expenseTotal;
        }

        $total_sales = Invoice::whereBetween(
            'invoice_date',
            [$startDate->format('Y-m-d'), $periodEnd->format('Y-m-d')]
        )
            ->whereCompany()
            ->sum('base_total');

        $total_receipts = Payment::whereBetween(
            'payment_date',
            [$startDate->format('Y-m-d'), $periodEnd->format('Y-m-d')]
        )
            ->whereCompany()
            ->sum('base_amount');

        $total_expenses = Expense::whereBetween(
            'expense_date',
            [$startDate->format('Y-m-d'), $periodEnd->format('Y-m-d')]
        )
            ->whereCompany()
            ->sum('base_amount');

        $total_net_income = (int) $total_receipts - (int) $total_expenses;

        $chart_data = [
            'months' => $months,
            'invoice_totals' => $invoice_totals,
            'expense_totals' => $expense_totals,
            'receipt_totals' => $receipt_totals,
            'net_income_totals' => $net_income_totals,
        ];

        $total_customer_count = Customer::whereCompany()->count();
        $total_invoice_count = Invoice::whereCompany()
            ->count();
        $total_estimate_count = Estimate::whereCompany()->count();
        $total_amount_due = Invoice::whereCompany()
            ->sum('base_due_amount');

        $recent_due_invoices = Invoice::with(['customer.currency', 'currency'])
            ->whereCompany()
            ->where('base_due_amount', '>', 0)
            ->take(5)
            ->latest()
            ->get();
        $recent_estimates = Estimate::with(['customer.currency', 'currency'])
            ->whereCompany()
            ->take(5)
            ->latest()
            ->get();

        return response()->json([
            'total_amount_due' => $total_amount_due,
            'total_customer_count' => $total_customer_count,
            'total_invoice_count' => $total_invoice_count,
            'total_estimate_count' => $total_estimate_count,

            'recent_due_invoices' => BouncerFacade::can('view-invoice', Invoice::class) ? $recent_due_invoices : [],
            'recent_estimates' => BouncerFacade::can('view-estimate', Estimate::class) ? $recent_estimates : [],

            'chart_data' => $chart_data,

            'total_sales' => $total_sales,
            'total_receipts' => $total_receipts,
            'total_expenses' => $total_expenses,
            'total_net_income' => $total_net_income,
        ]);
    }

    private function getMonthlySums($query, string $dateColumn, string $sumColumn): array
    {
        $driver = DB::connection()->getDriverName();
        $expression = match ($driver) {
            'sqlite' => "strftime('%Y-%m', {$dateColumn})",
            'pgsql' => "to_char({$dateColumn}, 'YYYY-MM')",
            default => "DATE_FORMAT({$dateColumn}, '%Y-%m')",
        };

        return $query
            ->selectRaw("{$expression} as month_key, SUM({$sumColumn}) as total")
            ->groupBy('month_key')
            ->pluck('total', 'month_key')
            ->toArray();
    }
}
