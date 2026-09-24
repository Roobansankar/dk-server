<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComboRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('products.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
	    'bundle_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
	    'tax_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'image' => [
                'nullable', 'file', 'image',
                'mimes:'.implode(',', config('salon.uploads.mimes')),
                'max:'.config('salon.uploads.max_kb'),
            ],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            // Included products, each with its combo-specific price.
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'items.*.price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
        ];
    }

    public function attributes(): array
    {
        return [
            'items.*.product_id' => 'product',
            'items.*.price' => 'combo price',
        ];
    }
}
