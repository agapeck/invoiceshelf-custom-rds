<!DOCTYPE html>
<html>

<head>
    <title>@lang('pdf_invoice_label') - {{ $invoice->invoice_number }}</title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />

    <style type="text/css">
        /* -- Base -- */

        body {
            font-family: "DejaVu Sans";
        }

        html {
            margin: 0px;
            padding: 0px;
            margin-top: 50px;
        }

        table {
            border-collapse: collapse;
        }

        hr {
            color: rgba(0, 0, 0, 0.2);
            border: 0.5px solid #EAF1FB;
        }

        /* -- Header -- */

        .header-container {
            margin-top: -30px;
            width: 100%;
            padding: 0px 30px;
        }

        .header-logo {

            text-transform: capitalize;
            color: #817AE3;
            padding-top: 0px;
        }

        .company-address-container {
            width: 50%;
            margin-bottom: 2px;
            padding-right: 60px;
        }

        .company-address {
            margin-top: 12px;
            font-size: 12px;
            line-height: 15px;
            color: #595959;
            word-wrap: break-word;
        }

        /* -- Content Wrapper  */

        .content-wrapper {
            display: block;
            padding-top: 0px;
            padding-bottom: 20px;
        }

        .customer-address-container {
            display: block;
            float: left;
            width: 45%;
            padding: 10px 0 0 30px;
        }

        /* -- Shipping -- */
        .shipping-address-container {
            float: right;
            display: block;
        }

        .shipping-address-container--left {
            float: left;
            display: block;
            padding-left: 0;
        }

        .shipping-address {
            font-size: 10px;
            line-height: 15px;
            color: #595959;
            margin-top: 5px;
            width: 160px;
            word-wrap: break-word;
        }

        /* -- Billing -- */

        .billing-address-container {
            display: block;
            float: left;
        }

        .billing-address {
            font-size: 10px;
            line-height: 15px;
            color: #595959;
            margin-top: 5px;
            width: 160px;
            word-wrap: break-word;
        }

        /* -- Patient Information -- */

        .patient-info-container {
            clear: both;
            margin-top: 30px;
            padding: 20px 30px;
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
        }

        .patient-info-title {
            font-size: 14px;
            font-weight: bold;
            color: #374151;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #6366f1;
        }

        .patient-info-grid {
            display: table;
            width: 100%;
        }

        .patient-info-row {
            display: table-row;
        }

        .patient-info-cell {
            display: table-cell;
            padding: 8px 15px;
            width: 50%;
            vertical-align: top;
        }

        .patient-info-label {
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 4px;
        }

        .patient-info-value {
            font-size: 12px;
            color: #111827;
            font-weight: 500;
        }

        .patient-info-full-width {
            display: block;
            padding: 8px 15px;
            width: 100%;
        }

        /* -- Bible Verse Footer -- */

        .bible-verse-footer {
            position: fixed;
            bottom: 20px;
            left: 0;
            right: 0;
            text-align: center;
            font-style: italic;
            font-size: 10px;
            color: #666;
            padding: 10px 30px;
            border-top: 1px solid #E8E8E8;
            background-color: #f9f9f9;
            page-break-inside: avoid;
            margin: 0 20px;
        }

        /*  -- Estimate Details -- */

        .invoice-details-container {
            display: block;
            float: right;
            padding: 10px 30px 0 0;
        }

        .attribute-label {
            font-size: 12px;
            line-height: 18px;
            text-align: left;
            color: #55547A
        }

        .attribute-value {
            font-size: 12px;
            line-height: 18px;
            text-align: right;
        }

        /* -- Items Table -- */

        .items-table {
            margin-top: 35px;
            padding: 0px 30px 10px 30px;
            page-break-before: avoid;
            page-break-after: auto;
        }

        .items-table hr {
            height: 0.1px;
        }

        .item-table-heading {
            font-size: 13.5;
            text-align: center;
            color: rgba(0, 0, 0, 0.85);
            padding: 5px;
            color: #55547A;
        }

        tr.item-table-heading-row th {
            border-bottom: 0.620315px solid #E8E8E8;
            font-size: 12px;
            line-height: 18px;
        }

        tr.item-row td {
            font-size: 12px;
            line-height: 18px;
        }

        .item-cell {
            font-size: 13;
            text-align: center;
            padding: 5px;
            padding-top: 10px;
            color: #040405;
        }

        .item-description {
            color: #595959;
            font-size: 9px;
            line-height: 12px;
            display: block;
        }

        .item-cell-table-hr {
            margin: 0 30px 0 30px;
        }

        /* -- Total Display Table -- */

        .total-display-container {
            padding: 0 25px;
        }


        .total-display-table {
            border-top: none;
            page-break-inside: avoid;
            page-break-before: auto;
            page-break-after: auto;
            margin-top: 20px;
            float: right;
            width: auto;
        }

        .total-table-attribute-label {
            font-size: 12px;
            color: #55547A;
            text-align: left;
            padding-left: 10px;
        }

        .total-table-attribute-value {
            font-weight: bold;
            text-align: right;
            font-size: 12px;
            color: #040405;
            padding-right: 10px;
            padding-top: 2px;
            padding-bottom: 2px;
        }

        .total-border-left {
            border: 1px solid #E8E8E8 !important;
            border-right: 0px !important;
            padding-top: 0px;
            padding: 8px !important;
        }

        .total-border-right {
            border: 1px solid #E8E8E8 !important;
            border-left: 0px !important;
            padding-top: 0px;
            padding: 8px !important;
        }

        /* -- Notes -- */
        .notes {
            font-size: 12px;
            color: #595959;
            margin-top: 15px;
            margin-left: 30px;
            width: 442px;
            text-align: left;
            page-break-inside: avoid;
        }

        .notes-label {
            font-size: 15px;
            line-height: 22px;
            letter-spacing: 0.05em;
            color: #040405;
            width: 108px;
            white-space: nowrap;
            height: 19.87px;
            padding-bottom: 10px;
        }

        /* -- Helpers -- */

        .text-primary {
            color: #5851DB;
        }

        .text-center {
            text-align: center
        }

        table .text-left {
            text-align: left;
        }

        table .text-right {
            text-align: right;
        }

        .border-0 {
            border: none;
        }

        .py-2 {
            padding-top: 2px;
            padding-bottom: 2px;
        }

        .py-8 {
            padding-top: 8px;
            padding-bottom: 8px;
        }

        .py-3 {
            padding: 3px 0;
        }

        .pr-20 {
            padding-right: 20px;
        }

        .pr-10 {
            padding-right: 10px;
        }

        .pl-20 {
            padding-left: 20px;
        }

        .pl-10 {
            padding-left: 10px;
        }

        .pl-0 {
            padding-left: 0;
        }

    </style>

    @if (App::isLocale('th'))
        @include('app.pdf.locale.th')
    @endif
</head>

<body>
    <div class="header-container">
        <table width="100%">
            <tr>
                <td width="50%" class="header-section-left">
                    @if ($logo)
                        <img class="header-logo" style="height:50px" src="{{ \App\Space\ImageUtils::toBase64Src($logo) }}" alt="Company Logo">
                    @else
                        <h1 class="header-logo"> {{ $invoice->customer->company->name }} </h1>
                    @endif
                </td>
                <td width="50%" class="text-right company-address-container company-address">
                    {!! $company_address !!}
                </td>
            </tr>
        </table>
    </div>

    <hr class="header-bottom-divider">

    <div class="content-wrapper">
        <div class="main-content">
            <div class="customer-address-container">
                <div class="billing-address-container billing-address">
                    @if ($billing_address)
                        <b>@lang('pdf_bill_to')</b> <br>
                        {!! $billing_address !!}
                    @endif
                </div>

                <div @if ($billing_address !== '</br>') class="shipping-address-container shipping-address" @else class="shipping-address-container--left shipping-address" @endif>
                    @if ($shipping_address)
                        <b>@lang('pdf_ship_to')</b> <br>
                        {!! $shipping_address !!}
                    @endif
                </div>
                <div style="clear: both;"></div>
            </div>

            <div class="invoice-details-container">
                <table>
                    <tr>
                        <td class="attribute-label">@lang('pdf_invoice_number')</td>
                        <td class="attribute-value"> &nbsp;{{ $invoice->invoice_number }}</td>
                    </tr>
                    <tr>
                        <td class="attribute-label">@lang('pdf_invoice_date')</td>
                        <td class="attribute-value"> &nbsp;{{ $invoice->formattedInvoiceDate }}</td>
                    </tr>
                    <tr>
                        <td class="attribute-label">@lang('pdf_invoice_due_date')</td>
                        <td class="attribute-value"> &nbsp;{{ $invoice->formattedDueDate }}</td>
                    </tr>
                </table>
            </div>
            <div style="clear: both;"></div>
        </div>

        {{-- Patient Information Section --}}
        @if ($invoice->customer_age || $invoice->customer_next_of_kin || $invoice->customer_diagnosis || $invoice->customer_treatment)
            <div class="patient-info-container">
                <div class="patient-info-title">
                    @lang('pdf_patient_information')
                </div>
                <div class="patient-info-grid">
                    @if ($invoice->customer_age || $invoice->customer_next_of_kin)
                        <div class="patient-info-row">
                            @if ($invoice->customer_age)
                                <div class="patient-info-cell">
                                    <div class="patient-info-label">@lang('pdf_age')</div>
                                    <div class="patient-info-value">{{ $invoice->customer_age }}</div>
                                </div>
                            @endif
                            @if ($invoice->customer_next_of_kin)
                                <div class="patient-info-cell">
                                    <div class="patient-info-label">@lang('pdf_next_of_kin')</div>
                                    <div class="patient-info-value">{{ $invoice->customer_next_of_kin }}</div>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($invoice->customer_next_of_kin_phone || $invoice->customer_attended_to_by)
                        <div class="patient-info-row">
                            @if ($invoice->customer_next_of_kin_phone)
                                <div class="patient-info-cell">
                                    <div class="patient-info-label">@lang('pdf_next_of_kin_phone')</div>
                                    <div class="patient-info-value">{{ $invoice->customer_next_of_kin_phone }}</div>
                                </div>
                            @endif
                            @if ($invoice->customer_attended_to_by)
                                <div class="patient-info-cell">
                                    <div class="patient-info-label">@lang('pdf_attended_to_by')</div>
                                    <div class="patient-info-value">{{ $invoice->customer_attended_to_by }}</div>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($invoice->customer_review_date)
                        <div class="patient-info-row">
                            <div class="patient-info-cell">
                                <div class="patient-info-label">@lang('pdf_review_date')</div>
                                <div class="patient-info-value">
                                    {{ \Carbon\Carbon::parse($invoice->customer_review_date)->format('F d, Y') }}
                                </div>
                            </div>
                            <div class="patient-info-cell">
                                <!-- Empty cell for layout -->
                            </div>
                        </div>
                    @endif

                    @if ($invoice->customer_diagnosis)
                        <div class="patient-info-full-width">
                            <div class="patient-info-label">@lang('pdf_diagnosis')</div>
                            <div class="patient-info-value">{{ $invoice->customer_diagnosis }}</div>
                        </div>
                    @endif

                    @if ($invoice->customer_treatment)
                        <div class="patient-info-full-width">
                            <div class="patient-info-label">@lang('pdf_treatment')</div>
                            <div class="patient-info-value">{{ $invoice->customer_treatment }}</div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @include('app.pdf.invoice.partials.table')

        <div class="notes">
            @if ($notes)
                <div class="notes-label">
                    @lang('pdf_notes')
                </div>

                {!! $notes !!}
            @endif
        </div>
    </div>

    <!-- Bible Verse Footer -->
    <div class="bible-verse-footer">
        <em>God's Grace is sufficient to heal you! - 2 Cor 12:9 ESV</em>
    </div>
</body>

</html>
