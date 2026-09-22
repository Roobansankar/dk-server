<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSiteSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.manage');
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
            // Opening hours are stored as zero-padded 24-hour "H:i" strings.
            'settings.shop_opens_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'settings.shop_closes_at' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $open = $this->input('settings.shop_opens_at');
            $close = $this->input('settings.shop_closes_at');

            if ($open && $close && strtotime($close) <= strtotime($open)) {
                $validator->errors()->add(
                    'settings.shop_closes_at',
                    'The closing time must be later than the opening time.',
                );
            }
        });
    }
}
