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
        $exists = Rule::exists('services', 'id')->whereNull('deleted_at');

        return [
            // Either just the complete list of service ids (prices already set
            // are kept) …
            'service_ids' => ['exclude_with:services', 'present', 'array'],
            'service_ids.*' => ['integer', 'distinct', $exists],

            // … or the complete list with this professional's own terms for each
            // service. A blank price / advance means "use the service's standard".
            'services' => ['sometimes', 'array'],
            'services.*.id' => ['required', 'integer', 'distinct', $exists],
            'services.*.price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'services.*.advance_percentage' => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}
