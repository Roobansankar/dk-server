<?php

namespace App\Http\Requests;

use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\Stylist;
use App\Support\AppointmentSlots;
use App\Support\BookingAvailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Belt-and-braces alongside the route's `auth:sanctum` middleware —
        // an online booking always belongs to a signed-in customer.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'gender' => ['required', 'string', Rule::in(['male', 'female', 'unisex'])],
            'category_id' => ['required', 'integer', Rule::exists('service_categories', 'id')->where('status', true)],
            'service_id' => ['required_without:service_ids', 'nullable', 'integer', Rule::exists('services', 'id')->where('status', true)],
            'service_ids' => ['required_without:service_id', 'nullable', 'array', 'min:1', 'max:10'],
            'service_ids.*' => ['integer', Rule::exists('services', 'id')->where('status', true)],
            'stylist_id' => ['nullable', 'integer', Rule::exists('stylists', 'id')->where('status', true)],
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'appointment_time' => ['required', 'date_format:H:i'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Multi-service (any mix allowed): every id must be live. The
            // category check below only applies to the legacy single-service
            // payload; the snapshot takes its category from the first service.
            $multi = $this->filled('service_ids');
            $ids = $multi
                ? collect($this->input('service_ids'))->map(fn ($id) => (int) $id)->unique()->values()
                : collect([$this->integer('service_id')]);

            $services = Service::with('category')->whereIn('id', $ids)->get();

            if ($services->count() !== $ids->count()) {
                $validator->errors()->add('service_ids', 'One of the selected services is no longer available.');

                return;
            }

            $services = $services->sortBy(fn ($s) => $ids->search((int) $s->id))->values();
            $service = $services->first();
            $duration = (int) $services->sum('duration_minutes');

            if (! $multi && (! $service || $service->service_category_id !== $this->integer('category_id'))) {
                $validator->errors()->add('service_id', 'The selected service does not belong to that category.');

                return;
            }

            // The request's FULL duration must fall within the studio's saved
            // opening hours (see the admin Settings "Shop Hours" section).
            // Skipped entirely when either bound is unset. Multi-service uses
            // the SUMMED duration (one combined slot).
            $settings = SiteSetting::allValues();
            $opensAt = $settings->get('shop_opens_at');
            $closesAt = $settings->get('shop_closes_at');
            $time = $this->input('appointment_time');
            $endTime = AppointmentSlots::endTime($time, $duration);

            if ($opensAt && $closesAt && $time && $endTime
                && (strtotime($time) < strtotime($opensAt) || strtotime($endTime) > strtotime($closesAt))
            ) {
                $validator->errors()->add(
                    'appointment_time',
                    sprintf(
                        'Please choose a time between %s and %s that leaves enough time for this service.',
                        date('g:i A', strtotime($opensAt)),
                        date('g:i A', strtotime($closesAt)),
                    ),
                );

                return;
            }

            // A request for today (studio time) must start at or after the
            // SAME rounded floor the booking form's picker generates its
            // first slot from (see AppointmentSlots::earliestBookableTime) —
            // not merely "after now". At 10:40 the floor is 10:50: a request
            // for 10:40, or anything back to 10:00, is rejected exactly like
            // the picker never offered it in the first place. This is the
            // authoritative check — the picker's own rounding is a display
            // convenience, this is what actually decides.
            $now = Carbon::now('Asia/Kolkata');
            $requestedDate = Carbon::parse($this->input('appointment_date'), 'Asia/Kolkata')->startOfDay();
            $earliestToday = AppointmentSlots::earliestBookableTime($requestedDate, $now);
            if ($time && $earliestToday
                && Carbon::parse($requestedDate->toDateString().' '.$time, 'Asia/Kolkata')->lt($earliestToday)
            ) {
                $validator->errors()->add(
                    'appointment_time',
                    'That time has already passed. Please choose a later time.',
                );

                return;
            }

            // The professional must offer the service(s), be working for the
            // whole (summed) duration, and be free (a confirmed appointment
            // locks its duration plus a buffer either side). With no
            // professional chosen ("any") at least one eligible one must
            // qualify. This is a fast, unlocked first pass purely for a
            // friendly validation message — it can't be the authoritative
            // check (nothing here is locked against a concurrent request), so
            // it's re-decided atomically, under a stylist/day lock,
            // immediately before the appointment is actually created (see
            // Public\AppointmentController::store).
            $stylist = $this->filled('stylist_id') ? Stylist::find($this->integer('stylist_id')) : null;

            [, $error] = $multi
                ? BookingAvailability::resolveServices($services, $stylist, $requestedDate->toDateString(), $time)
                : BookingAvailability::resolve($service, $stylist, $requestedDate->toDateString(), $time);

            if ($error) {
                $validator->errors()->add('appointment_time', $error);
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
