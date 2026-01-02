<!DOCTYPE html>
<html lang="en">

<head>
    <title>@lang('pdf_payments_dentists_label')</title>
    <style type="text/css">
        body {
            font-family: "DejaVu Sans";
        }

        table {
            border-collapse: collapse;
        }

        .sub-container {
            padding: 0px 20px;
        }

        .report-header {
            width: 100%;
        }

        .heading-text {
            font-weight: bold;
            font-size: 24px;
            color: #5851D8;
            width: 100%;
            text-align: left;
            padding: 0px;
            margin: 0px;
        }

        .heading-date-range {
            font-weight: normal;
            font-size: 15px;
            color: #A5ACC1;
            width: 100%;
            text-align: right;
            padding: 0px;
            margin: 0px;
        }

        .sub-heading-text {
            font-weight: bold;
            font-size: 16px;
            line-height: 21px;
            color: #595959;
            padding: 0px;
            margin: 0px;
            margin-top: 30px;
        }

        .dentist-name {
            margin-top: 20px;
            padding-left: 3px;
            font-size: 16px;
            line-height: 21px;
            color: #040405;
            font-weight: bold;
        }

        .dentist-stats {
            font-size: 12px;
            color: #A5ACC1;
            font-weight: normal;
            margin-left: 10px;
        }

        .payments-table-container {
            padding-left: 10px;
        }

        .payments-table {
            width: 100%;
            padding-bottom: 10px;
        }

        .payments-information-text {
            padding: 0px;
            margin: 0px;
            font-size: 14px;
            line-height: 21px;
            color: #595959;
        }

        .payments-customer {
            font-size: 12px;
            color: #A5ACC1;
        }

        .payments-amount {
            padding: 0px;
            margin: 0px;
            font-size: 14px;
            line-height: 21px;
            text-align: right;
            color: #595959;
        }

        .payments-total-indicator-table {
            border-top: 1px solid #EAF1FB;
            width: 100%;
        }

        .payments-total-cell {
            padding-top: 10px;
        }

        .payments-total-amount {
            padding-top: 10px;
            padding-right: 30px;
            padding: 0px;
            margin: 0px;
            text-align: right;
            font-weight: bold;
            font-size: 16px;
            line-height: 21px;
            text-align: right;
            color: #040405;
        }

        .report-footer {
            width: 100%;
            margin-top: 40px;
            padding: 15px 20px;
            background: #F9FBFF;
            box-sizing: border-box;
        }

        .report-footer-label {
            padding: 0px;
            margin: 0px;
            text-align: left;
            font-weight: bold;
            font-size: 16px;
            line-height: 21px;
            color: #595959;
        }

        .report-footer-value {
            padding: 0px;
            margin: 0px;
            text-align: right;
            font-weight: bold;
            font-size: 20px;
            line-height: 21px;
            color: #5851D8;
        }

        .text-center {
            text-align: center;
        }

        .no-data {
            text-align: center;
            color: #A5ACC1;
            font-size: 14px;
            padding: 40px 0;
        }
    </style>

    @if (App::isLocale('th'))
    @include('app.pdf.locale.th')
    @endif
</head>

<body>
    <div class="sub-container">
        <table class="report-header">
            <tr>
                <td>
                    <p class="heading-text">{{ $company->name }}</p>
                </td>
                <td>
                    <p class="heading-date-range">{{ $from_date }} - {{ $to_date }}</p>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <p class="sub-heading-text text-center">@lang('pdf_dentist_payments_report')</p>
                </td>
            </tr>
        </table>

        @if (count($dentistPayments) > 0)
            @foreach ($dentistPayments as $data)
            <p class="dentist-name">
                {{ $data['name'] }}
                <span class="dentist-stats">({{ $data['paymentCount'] }} {{ $data['paymentCount'] == 1 ? 'payment' : 'payments' }})</span>
            </p>
            <div class="payments-table-container">
                <table class="payments-table">
                    @foreach ($data['payments'] as $payment)
                    <tr>
                        <td>
                            <p class="payments-information-text">
                                {{ $payment->formattedPaymentDate }} ({{ $payment->payment_number }})
                                <span class="payments-customer">- {{ $payment->customer?->name ?? 'Unknown Customer' }}</span>
                            </p>
                        </td>
                        <td>
                            <p class="payments-amount">
                                {!! format_money_pdf($payment->base_amount, $currency) !!}
                            </p>
                        </td>
                    </tr>
                    @endforeach
                </table>
            </div>
            <table class="payments-total-indicator-table">
                <tr>
                    <td class="payments-total-cell">
                        <p class="payments-total-amount">
                            {!! format_money_pdf($data['totalAmount'], $currency) !!}
                        </p>
                    </td>
                </tr>
            </table>
            @endforeach
        @else
            <p class="no-data">@lang('pdf_no_dentist_payments_data')</p>
        @endif
    </div>

    <table class="report-footer">
        <tr>
            <td>
                <p class="report-footer-label">@lang('pdf_total_payments_label')</p>
            </td>
            <td>
                <p class="report-footer-value">
                    {!! format_money_pdf($totalAmount, $currency) !!}
                </p>
            </td>
        </tr>
    </table>
</body>

</html>
