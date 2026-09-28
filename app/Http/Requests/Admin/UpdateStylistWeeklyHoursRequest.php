<?php

namespace App\Http\Requests\Admin;

use App\Support\WorkRangesValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateStylistWeeklyHoursRequest extends FormRequest
{
    /**
     * Ranges allowed on one weekday — kept in step with
     * UpdateStylistDateHoursRequest::MAX_RANGES_PER_DAY and the admin
     * StylistSetup page's own MAX_RANGES.
     */
    public const MAX_RANGES_PER_DAY = 12;

    public function authorize(): bool
    {
        return $this->user()->can('stylists.manage');
    }

    public function rules(): array
    {
        return [
            // Each entry sets the standing hours for ONE weekday (0 = Sunday …
            // 6 = Saturday): the ranges given replace whatever that weekday had
            // before, and an empty list clears it (no standing hours that day).
            // A weekday not sent here is left exactly as it was.
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*.weekday' => ['required', 'integer', 'min:0', 'max:6', 'distinct'],
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

            foreach ($this->input('days', []) as $d => $day) {
                $ranges = $day['ranges'] ?? [];

                if ($ranges !== []) {
                    WorkRangesValidator::validate($validator, $ranges, "days.$d.ranges");
                }
            }
        });
    }
}
