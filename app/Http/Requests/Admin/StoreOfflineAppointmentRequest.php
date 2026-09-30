<?php

namespace App\Http\Requests\Admin;

use App\Models\Appointment;
use App\Models\PricingPlan;
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
            // Either services (category → services) or a Combo Offer (pricing_plan_id).
            'category_id' => ['required_without:pricing_plan_id', 'nullable', 'integer', Rule::exists('service_categories', 'id')->where('status', true)],
            'service_id' => ['required_without_all:pricing_plan_id,service_ids', 'nullable', 'integer', Rule::exists('services', 'id')->where('status', true)],
            'service_ids' => ['required_without_all:pricing_plan_id,service_id', 'nullable', 'array', 'min:1', 'max:10'],
            'service_ids.*' => ['integer', Rule::exists('services', 'id')->where('status', true)],
            'pricing_plan_id' => [
                'nullable', 'integer', 'prohibits:service_id', 'prohibits:service_ids',
                Rule::exists('pricing_plans', 'id')->where('status', true)->whereNull('deleted_at'),
            ],
            'stylist_id' => ['required', 'integer', Rule::exists('stylists', 'id')->where('status', true)],
            // Staff may backdate a walk-in that already happened.
            'appointment_date' => ['required', 'date'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'payment_status' => ['sometimes', Rule::in(Appointment::PAYMENT_STATUSES)],
            'payment_method' => ['nullable', 'string', Rule::in(Appointment::PAYMENT_METHODS)],
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

            if ($this->filled('pricing_plan_id')) {
                if (! PricingPlan::find($this->integer('pricing_plan_id'))?->duration_minutes) {
                    $validator->errors()->add('pricing_plan_id', 'Set a time on this plan (Pricing Plans → Edit) before booking it.');
                }

                return;
            }

            // Multi-service (any mix allowed): every id must be live. The
            // category check only applies to the legacy single-service payload.
            if ($this->filled('service_ids')) {
                $ids = collect($this->input('service_ids'))->map(fn ($id) => (int) $id)->unique();

                if (Service::whereIn('id', $ids)->count() !== $ids->count()) {
                    $validator->errors()->add('service_ids', 'One of the selected services is no longer available.');
                }

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
            'pricing_plan_id' => 'combo offer',
            'stylist_id' => 'stylist',
        ];
    }
}
