<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesGalleryImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateComboRequest extends FormRequest
{
    use ValidatesGalleryImages;

    public function authorize(): bool
    {
        return $this->user()->can('products.update');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->checkGallery($validator, $this->route('combo')));
    }

    public function rules(): array
    {
        return [
            ...$this->galleryRules(),
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
	    'bundle_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
	    'tax_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'image' => [
                'sometimes', 'file', 'image',
                'mimes:'.implode(',', config('salon.uploads.mimes')),
                'max:'.config('salon.uploads.max_kb'),
            ],
            'remove_image' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            // When present, replaces the combo's whole product/price list.
            'items' => ['sometimes', 'array', 'min:1', 'max:50'],
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
