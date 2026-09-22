<?php

namespace App\Http\Requests\Admin;

use App\Models\ServiceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('services.create');
    }

    public function rules(): array
    {
        return [
            'gender' => ['required', Rule::in(ServiceCategory::GENDERS)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_type' => ['nullable', Rule::in(ServiceCategory::TYPES)],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'image' => $this->imageRules(),
        ];
    }

    protected function imageRules(): array
    {
        return [
            'nullable', 'file', 'image',
            'mimes:'.implode(',', config('salon.uploads.mimes')),
            'max:'.config('salon.uploads.max_kb'),
        ];
    }
}
