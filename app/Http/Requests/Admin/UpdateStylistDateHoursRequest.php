<?php

namespace App\Http\Requests\Admin;

use App\Support\BookingAvailability;
use App\Support\WorkRangesValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class UpdateStylistDateHoursRequest extends FormRequest
{
    /** How far ahead the calendar can be edited. */
    public const MAX_DAYS_AHEAD = 400;

    /** Ranges allowed in one day — enough for a split shift, not a free-for-all. */
    public const MAX_RANGES_PER_DAY = 4;

    public function authorize(): bool
    {
        return $this->user()->can('stylists.manage');
    }

    public function rules(): array
    {
        return [
            // Each entry sets ONE calendar date: `custom` (the professional can
            // be booked that day, within the given ranges) or `clear` (remove the
            // hours — not available that day). Dates not sent are left as they are.
            'days' => ['required', 'array', 'min:1', 'max:366'],
            'days.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'days.*.mode' => ['required', 'in:custom,clear'],
            'days.*.ranges' => ['nullable', 'array', 'max:'.self::MAX_RANGES_PER_DAY],
            'days.*.ranges.*.start' => ['required', 'date_format:H:i'],
            'days.*.ranges.*.end' => ['required', 'date_format:H:i'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $today = Carbon::now(BookingAvailability::TZ)->startOfDay();
            $last = $today->copy()->addDays(self::MAX_DAYS_AHEAD);

            foreach ($this->input('days', []) as $d => $day) {
                $date = Carbon::parse($day['date'], BookingAvailability::TZ)->startOfDay();

                if ($date->lt($today)) {
                    $validator->errors()->add("days.$d.date", 'You can only change today or a later date.');

                    continue;
                }

                if ($date->gt($last)) {
                    $validator->errors()->add("days.$d.date", 'That date is too far ahead.');

                    continue;
                }

                if ($day['mode'] !== 'custom') {
                    continue;
                }

                $ranges = $day['ranges'] ?? [];

                if ($ranges === []) {
                    $validator->errors()->add("days.$d.ranges", 'Add at least one range of hours.');

                    continue;
                }

                WorkRangesValidator::validate($validator, $ranges, "days.$d.ranges");
            }
        });
    }
}
