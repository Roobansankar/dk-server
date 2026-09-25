<?php

namespace App\Http\Requests\Admin;

use App\Support\WorkRangesValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateStylistWorkHoursRequest extends FormRequest
{
    /** Ranges allowed in one day — enough for a split shift, not a free-for-all. */
    public const MAX_RANGES_PER_DAY = 4;

    public function authorize(): bool
    {
        return $this->user()->can('stylists.manage');
    }

    public function rules(): array
    {
        return [
            // The whole week is replaced: a day that is missing, or has no
            // ranges, becomes a day off.
            'days' => ['required', 'array', 'max:7'],
            'days.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.ranges' => ['present', 'array', 'max:'.self::MAX_RANGES_PER_DAY],
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
                WorkRangesValidator::validate($validator, $day['ranges'], "days.$d.ranges");
            }
        });
    }
}
