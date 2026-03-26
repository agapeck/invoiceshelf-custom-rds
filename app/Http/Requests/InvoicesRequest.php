<?php

namespace App\Http\Requests;

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoicesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.s
     */
    public function rules(): array
    {
        $rules = [
            'invoice_date' => [
                'required',
            ],
            'due_date' => [
                'nullable',
            ],
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')
                    ->where('company_id', $this->header('company'))
                    ->whereNull('deleted_at'),
            ],
            'invoice_number' => [
                'required',
                Rule::unique('invoices')
                    ->where('company_id', $this->header('company'))
                    ->whereNull('deleted_at'),
            ],
            'exchange_rate' => [
                'nullable',
            ],
            'discount' => [
                'numeric',
                'required',
            ],
            'discount_val' => [
                'integer',
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
            'template_name' => [
                'required',
            ],
            'items' => [
                'required',
                'array',
            ],
            'items.*' => [
                'required',
                'max:255',
            ],
            'items.*.description' => [
                'nullable',
            ],
            'items.*.name' => [
                'required',
            ],
            'items.*.quantity' => [
                'numeric',
                'min:0',
                'required',
            ],
            'items.*.price' => [
                'numeric',
                'min:0',
                'required',
            ],
            'assigned_to_id' => [
                'nullable',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if (! $value) {
                        return;
                    }

                    $user = User::where('id', $value)
                        ->whereHas('companies', function ($q) {
                            $q->where('company_id', $this->header('company'));
                        })
                        ->first();

                    if (! $user) {
                        $fail('Selected user is not part of this company.');
                        return;
                    }

                    if (! $user->isA('dentist')) {
                        $fail(__('must_be_dentist'));
                    }
                },
            ],
        ];

        $companyCurrency = CompanySetting::getSetting('currency', $this->header('company'));

        $customer = Customer::where('company_id', $this->header('company'))->find($this->customer_id);

        if ($customer && $companyCurrency) {
            if ((string) $customer->currency_id !== $companyCurrency) {
                $rules['exchange_rate'] = [
                    'required',
                ];
            }
        }

        if ($this->isMethod('PUT')) {
            $rules['invoice_number'] = [
                'required',
                Rule::unique('invoices')
                    ->ignore($this->route('invoice')->id)
                    ->where('company_id', $this->header('company'))
                    ->whereNull('deleted_at'),
            ];
        }

        return $rules;
    }

    public function getInvoicePayload(): array
    {
        $company_currency = CompanySetting::getSetting('currency', $this->header('company'));
        $current_currency = $this->currency_id;
        $exchange_rate = $company_currency != $current_currency ? $this->exchange_rate : 1;
        $customer = Customer::where('company_id', $this->header('company'))->find($this->customer_id);
        $currency = $customer ? $customer->currency_id : null;

        return collect($this->except('items', 'taxes'))
            ->merge([
                'creator_id' => $this->user()->id ?? null,
                'status' => $this->has('invoiceSend') ? Invoice::STATUS_SENT : Invoice::STATUS_DRAFT,
                'paid_status' => Invoice::STATUS_UNPAID,
                'company_id' => $this->header('company'),
                'tax_per_item' => CompanySetting::getSetting('tax_per_item', $this->header('company')) ?? 'NO',
                'discount_per_item' => CompanySetting::getSetting('discount_per_item', $this->header('company')) ?? 'NO',
                'due_amount' => $this->total,
                'sent' => (bool) $this->sent ?? false,
                'viewed' => (bool) $this->viewed ?? false,
                'exchange_rate' => $exchange_rate,
                'base_total' => $this->total * $exchange_rate,
                'base_discount_val' => $this->discount_val * $exchange_rate,
                'base_sub_total' => $this->sub_total * $exchange_rate,
                'base_tax' => $this->tax * $exchange_rate,
                'base_due_amount' => $this->total * $exchange_rate,
                'currency_id' => $currency,
                // Patient information snapshot
                'customer_age' => $customer?->age,
                'customer_next_of_kin' => $customer?->next_of_kin,
                'customer_next_of_kin_phone' => $customer?->next_of_kin_phone,
                'customer_diagnosis' => $customer?->diagnosis,
                'customer_treatment' => $customer?->treatment,
                'customer_attended_to_by' => $this->assigned_to_id
                    ? User::where('id', $this->assigned_to_id)
                        ->whereHas('companies', function ($q) {
                            $q->where('company_id', $this->header('company'));
                        })
                        ->value('name')
                    : $customer?->attended_to_by,
                'customer_review_date' => $customer?->review_date,
                'assigned_to_id' => $this->assigned_to_id,
            ])
            ->toArray();
    }
}
