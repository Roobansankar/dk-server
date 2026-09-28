<?php

namespace App\Http\Requests\Admin;

use App\Models\StudioHoliday;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStudioHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('stylists.manage');
    }

    public function rules(): array
    {
        return [
            // See StoreStudioHolidayRequest: whereDate keeps the duplicate
            // check correct on both SQLite and MySQL.
            'date' => [
                'sometimes',
                'date_format:Y-m-d',
                function (string $attribute, string $value, callable $fail): void {
                    $conflict = StudioHoliday::query()
                        ->whereDate('date', $value)
                        ->whereKeyNot($this->route('studioHoliday')->getKey())
                        ->exists();

                    if ($conflict) {
                        $fail('That date is already marked as a studio holiday.');
                    }
                },
            ],
            'name' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
