<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AppointmentRequest extends FormRequest
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
            'customer_id' => 'required|exists:customers,id',
            'company_id' => 'required|exists:companies,id',
            'creator_id' => 'nullable|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'appointment_date' => 'required|date',
            'duration_minutes' => 'required|integer|in:15,30,45,60,90,120',
            'status' => 'required|in:scheduled,confirmed,completed,cancelled,no_show',
            'type' => 'required|in:consultation,follow_up,cleaning,filling,extraction,root_canal,crown_bridge,denture,whitening,pediatric,ortho_consult,treatment,emergency,other',
            'patient_name' => 'nullable|string|max:255',
            'patient_phone' => 'nullable|string|max:255',
            'patient_email' => 'nullable|email|max:255',
            'chief_complaint' => 'nullable|string',
            'notes' => 'nullable|string',
            'preparation_instructions' => 'nullable|string',
            'send_reminder' => 'boolean',
            'reminder_hours_before' => 'nullable|integer|min:1|max:168',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();
        $requestedCompanyId = $this->header('company');

        $companyId = null;

        if ($user) {
            if ($requestedCompanyId && $user->hasCompany($requestedCompanyId)) {
                $companyId = $requestedCompanyId;
            } else {
                $companyId = optional($user->companies()->first())->id;
            }
        }

        if (! $companyId) {
            abort(403, 'Invalid company context for appointment request.');
        }

        $this->merge([
            'company_id' => $companyId,
            'creator_id' => $user->id ?? null,
        ]);
    }
}
