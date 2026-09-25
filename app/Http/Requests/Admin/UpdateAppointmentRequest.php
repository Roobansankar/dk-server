<?php

namespace App\Http\Requests\Admin;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('appointments.manage');
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::in(Appointment::STATUSES)],
            'payment_status' => ['sometimes', 'required', Rule::in(Appointment::PAYMENT_STATUSES)],
            // Only staff-recorded (offline) appointments carry a payment method.
            'payment_method' => [
                Rule::prohibitedIf($this->route('appointment')?->source !== Appointment::SOURCE_OFFLINE),
                'sometimes', 'nullable', 'string', Rule::in(Appointment::PAYMENT_METHODS),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'appointment_date' => ['sometimes', 'required', 'date'],
            'appointment_time' => ['sometimes', 'required', 'date_format:H:i'],
        ];
    }
}
