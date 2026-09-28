<?php

namespace App\Http\Requests\Admin;

use App\Models\StudioHoliday;
use Illuminate\Foundation\Http\FormRequest;

class StoreStudioHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('stylists.manage');
    }

    public function rules(): array
    {
        return [
            // whereDate (not a plain unique rule): the date cast stores a
            // full datetime string on SQLite but a bare date on MySQL, so a
            // column-equality check would miss duplicates on one of them.
            'date' => [
                'required',
                'date_format:Y-m-d',
                function (string $attribute, string $value, callable $fail): void {
                    if (StudioHoliday::query()->whereDate('date', $value)->exists()) {
                        $fail('That date is already marked as a studio holiday.');
                    }
                },
            ],
            'name' => ['required', 'string', 'max:100'],
        ];
    }
}
