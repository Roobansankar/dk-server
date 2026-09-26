<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesGalleryImages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    use ValidatesGalleryImages;

    public function authorize(): bool
    {
        return $this->user()->can('products.update');
    }

    public function rules(): array
    {
        return [
            ...$this->galleryRules(),
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'image' => [
                'sometimes', 'file', 'image',
                'mimes:'.implode(',', config('salon.uploads.mimes')),
                'max:'.config('salon.uploads.max_kb'),
            ],
            'remove_image' => ['sometimes', 'boolean'],
            'mrp' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999.99'],
            'selling_price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999.99'],
	    'tax_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'gst_inclusive' => ['sometimes', 'boolean'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'status' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Selling price must stay at or below MRP, comparing against whichever
     * value each side ends up with (submitted value, else the stored one).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->checkGallery($validator, $this->route('product'));

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $product = $this->route('product');
            $mrp = $this->has('mrp') ? (float) $this->input('mrp') : (float) $product?->mrp;
            $selling = $this->has('selling_price')
                ? (float) $this->input('selling_price')
                : (float) $product?->selling_price;

            if ($mrp && $selling > $mrp) {
                $validator->errors()->add('selling_price', 'The selling price cannot be higher than the MRP.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'mrp' => 'MRP',
            'selling_price' => 'selling price',
        ];
    }
}
