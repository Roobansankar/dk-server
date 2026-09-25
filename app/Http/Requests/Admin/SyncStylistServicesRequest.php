<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncStylistServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('stylists.manage');
    }

    public function rules(): array
    {
        return [
            // The complete set of services this professional offers (may be empty).
            'service_ids' => ['present', 'array'],
            'service_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('services', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
