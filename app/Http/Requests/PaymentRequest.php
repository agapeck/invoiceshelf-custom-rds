<?php

namespace App\Http\Requests;

use App\Models\CompanySetting;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentRequest extends FormRequest
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
        $rules = [
            'payment_date' => [
                'required',
            ],
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')
                    ->where('company_id', $this->header('company'))
                    ->whereNull('deleted_at'),
            ],
            'exchange_rate' => [
                'nullable',
            ],
            'amount' => [
                'required',
                'numeric',
                'min:0',
                'max:999999999999',
            ],
            'payment_number' => [
                'required',
                Rule::unique('payments')
                    ->where('company_id', $this->header('company'))
                    ->whereNull('deleted_at'),
            ],
            'invoice_id' => [
                'nullable',
                Rule::exists('invoices', 'id')
                    ->where('company_id', $this->header('company'))
                    ->whereNull('deleted_at'),
            ],
            'payment_method_id' => [
                'nullable',
            ],
            'notes' => [
                'nullable',
            ],
        ];

        if ($this->isMethod('PUT')) {
            $rules['payment_number'] = [
                'required',
                Rule::unique('payments')
                    ->ignore($this->route('payment')->id)
                    ->where('company_id', $this->header('company'))
                    ->whereNull('deleted_at'),
            ];
        }

        $companyCurrency = CompanySetting::getSetting('currency', $this->header('company'));

        $customer = Customer::where('company_id', $this->header('company'))->find($this->customer_id);

        if ($customer && $companyCurrency) {
            if ((string) $customer->currency_id !== $companyCurrency) {
                $rules['exchange_rate'] = [
                    'required',
                ];
            }
        }

        return $rules;
    }

    public function getPaymentPayload()
    {
        $company_currency = CompanySetting::getSetting('currency', $this->header('company'));
        $current_currency = $this->currency_id;
        $exchange_rate = $company_currency != $current_currency ? $this->exchange_rate : 1;
        $customer = Customer::where('company_id', $this->header('company'))->find($this->customer_id);
        $currency = $customer ? $customer->currency_id : null;

        return collect($this->validated())
            ->merge([
                'creator_id' => $this->user()->id,
                'company_id' => $this->header('company'),
                'exchange_rate' => $exchange_rate,
                'base_amount' => $this->amount * $exchange_rate,
                'currency_id' => $currency,
            ])
            ->toArray();
    }
}
