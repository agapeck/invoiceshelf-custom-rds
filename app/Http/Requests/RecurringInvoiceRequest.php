<?php

namespace App\Http\Requests;

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\RecurringInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecurringInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $companyCurrency = CompanySetting::getSetting('currency', $this->header('company'));

        $rules = [
            'starts_at' => [
                'required',
            ],
            'send_automatically' => [
                'required',
                'boolean',
            ],
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')
                    ->where('company_id', $this->header('company'))
                    ->whereNull('deleted_at'),
            ],
            'exchange_rate' => [
                'nullable',
                'numeric',
                'min:0.0001',
                'max:999999999999',
            ],
            'discount' => [
                'numeric',
                'required',
            ],
            'discount_val' => [
                'numeric',
                'min:0',
                'required',
            ],
            'sub_total' => [
                'numeric',
                'min:0',
                'required',
            ],
            'total' => [
                'numeric',
                'min:0',
                'max:999999999999',
                'required',
            ],
            'tax' => [
                'required',
                'numeric',
                'min:0',
            ],
            'status' => [
                'required',
            ],
            'frequency' => [
                'required',
            ],
            'limit_by' => [
                'required',
            ],
            'limit_count' => [
                'required_if:limit_by,COUNT',
            ],
            'limit_date' => [
                'required_if:limit_by,DATE',
            ],
            'items' => [
                'required',
                'array',
            ],
            'items.*' => [
                'required',
            ],
        ];

        $customer = Customer::where('company_id', $this->header('company'))->find($this->customer_id);

        if ($customer && $companyCurrency) {
            if ((string) $customer->currency_id !== $companyCurrency) {
                $rules['exchange_rate'] = [
                    'required',
                    'numeric',
                    'min:0.0001',
                    'max:999999999999',
                ];
            }
        }

        return $rules;
    }

    public function getRecurringInvoicePayload()
    {
        $company_currency = CompanySetting::getSetting('currency', $this->header('company'));
        $current_currency = $this->currency_id;
        $exchange_rate = $company_currency != $current_currency ? $this->exchange_rate : 1;
        $customer = Customer::where('company_id', $this->header('company'))->find($this->customer_id);
        $currency = $customer ? $customer->currency_id : null;

        $nextInvoiceAt = RecurringInvoice::getNextInvoiceDate($this->frequency, $this->starts_at);

        return collect($this->except('items', 'taxes'))
            ->merge([
                'creator_id' => $this->user()->id,
                'company_id' => $this->header('company'),
                'next_invoice_at' => $nextInvoiceAt,
                'tax_per_item' => CompanySetting::getSetting('tax_per_item', $this->header('company')) ?? 'NO',
                'discount_per_item' => CompanySetting::getSetting('discount_per_item', $this->header('company')) ?? 'NO',
                'due_amount' => $this->total,
                'exchange_rate' => $exchange_rate,
                'currency_id' => $currency,
            ])
            ->toArray();
    }
}
