<?php

namespace App\Http\Requests\Admin;

use App\Models\Appointment;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A salon-staff-created appointment (walk-in, phone booking, etc.). Uses the
 * existing appointment table; the controller marks it source = offline and
 * snapshots the current service + stylist configuration.
 */
class StoreOfflineAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('appointments.offline');
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'gender' => ['required', 'string', Rule::in(['male', 'female', 'unisex'])],
            'category_id' => ['required', 'integer', Rule::exists('service_categories', 'id')->where('status', true)],
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')->where('status', true)],
            'stylist_id' => ['nullable', 'integer', Rule::exists('stylists', 'id')->where('status', true)],
            // Staff may backdate a walk-in that already happened.
            'appointment_date' => ['required', 'date'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'payment_status' => ['sometimes', Rule::in(Appointment::PAYMENT_STATUSES)],
            'status' => ['sometimes', Rule::in(Appointment::STATUSES)],
            'message' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $service = Service::find($this->integer('service_id'));

            if (! $service || $service->service_category_id !== $this->integer('category_id')) {
                $validator->errors()->add('service_id', 'The selected service does not belong to that category.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'category_id' => 'category',
            'service_id' => 'service',
            'stylist_id' => 'stylist',
        ];
    }
}
