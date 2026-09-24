<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
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
            'image' => [
                'nullable', 'file', 'image',
                'mimes:'.implode(',', config('salon.uploads.mimes')),
                'max:'.config('salon.uploads.max_kb'),
            ],
            'mrp' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            // Selling price cannot exceed MRP — there is no business rule that
            // allows selling above the printed maximum retail price.
            'selling_price' => ['required', 'numeric', 'min:0', 'max:9999999.99', 'lte:mrp'],
	    'tax_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'gst_inclusive' => ['sometimes', 'boolean'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'status' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'mrp' => 'MRP',
            'selling_price' => 'selling price',
        ];
    }

    public function messages(): array
    {
        return [
            'selling_price.lte' => 'The selling price cannot be higher than the MRP.',
        ];
    }
}
