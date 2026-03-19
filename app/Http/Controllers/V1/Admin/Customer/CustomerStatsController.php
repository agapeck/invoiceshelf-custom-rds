<?php

namespace App\Http\Controllers\V1\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerStatsController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, Customer $customer)
    {
        $this->authorize('view', $customer);

        $months = [];
        $monthKeys = [];
        $invoiceTotals = [];
        $expenseTotals = [];
        $receiptTotals = [];
        $netProfits = [];
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
            Invoice::whereCompany()
                ->whereCustomer($customer->id)
                ->whereBetween('invoice_date', [$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]),
            'invoice_date',
            'base_total'
        );
        $expenseMonthly = $this->getMonthlySums(
            Expense::whereCompany()
                ->whereUser($customer->id)
                ->whereBetween('expense_date', [$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]),
            'expense_date',
            'base_amount'
        );
        $paymentMonthly = $this->getMonthlySums(
            Payment::whereCompany()
                ->whereCustomer($customer->id)
                ->whereBetween('payment_date', [$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]),
            'payment_date',
            'base_amount'
        );

        foreach ($monthKeys as $monthKey) {
            $invoiceTotal = (float) ($invoiceMonthly[$monthKey] ?? 0);
            $expenseTotal = (float) ($expenseMonthly[$monthKey] ?? 0);
            $receiptTotal = (float) ($paymentMonthly[$monthKey] ?? 0);

            $invoiceTotals[] = $invoiceTotal;
            $expenseTotals[] = $expenseTotal;
            $receiptTotals[] = $receiptTotal;
            $netProfits[] = $receiptTotal - $expenseTotal;
        }

        $salesTotal = Invoice::whereBetween(
            'invoice_date',
            [$startDate->format('Y-m-d'), $periodEnd->format('Y-m-d')]
        )
            ->whereCompany()
            ->whereCustomer($customer->id)
            ->sum('base_total');
        $totalReceipts = Payment::whereBetween(
            'payment_date',
            [$startDate->format('Y-m-d'), $periodEnd->format('Y-m-d')]
        )
            ->whereCompany()
            ->whereCustomer($customer->id)
            ->sum('base_amount');
        $totalExpenses = Expense::whereBetween(
            'expense_date',
            [$startDate->format('Y-m-d'), $periodEnd->format('Y-m-d')]
        )
            ->whereCompany()
            ->whereUser($customer->id)
            ->sum('base_amount');
        $netProfit = (int) $totalReceipts - (int) $totalExpenses;

        $chartData = [
            'months' => $months,
            'invoiceTotals' => $invoiceTotals,
            'expenseTotals' => $expenseTotals,
            'receiptTotals' => $receiptTotals,
            'netProfit' => $netProfit,
            'netProfits' => $netProfits,
            'salesTotal' => $salesTotal,
            'totalReceipts' => $totalReceipts,
            'totalExpenses' => $totalExpenses,
        ];

        $customer->load(['billingAddress', 'shippingAddress', 'fields', 'company', 'currency', 'creator']);

        return (new CustomerResource($customer))
            ->additional(['meta' => [
                'chartData' => $chartData,
            ]]);
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
