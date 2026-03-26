<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class BulkExchangeRateRequest extends FormRequest
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
        return [
            'currencies' => [
                'required',
                'array',
                'min:1',
            ],
            'currencies.*.id' => [
                'required',
                'integer',
                Rule::exists('currencies', 'id'),
            ],
            'currencies.*.exchange_rate' => [
                'required',
                'numeric',
                'min:0.0001',
                'max:999999999999',
            ],
        ];
    }
}
