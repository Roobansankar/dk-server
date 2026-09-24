<?php

namespace App\Http\Requests;

use App\Models\OrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Start a product checkout (Buy Now / cart). Only *what* to buy is accepted —
 * any price, subtotal or total in the payload is ignored;
 * App\Support\OrderPricing prices every line from the database.
 */
class StoreProductCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Alongside the route's `auth:sanctum`: orders belong to customer
        // accounts, not staff.
        return $this->user()?->isCustomer() === true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.type' => ['required', 'string', Rule::in(OrderItem::TYPES)],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'items.*.product_id' => ['required_if:items.*.type,'.OrderItem::TYPE_PRODUCT, 'prohibited_unless:items.*.type,'.OrderItem::TYPE_PRODUCT, 'integer'],
            'items.*.combo_id' => ['required_if:items.*.type,'.OrderItem::TYPE_COMBO, 'prohibited_unless:items.*.type,'.OrderItem::TYPE_COMBO, 'integer'],
            'items.*.product_ids' => ['required_if:items.*.type,'.OrderItem::TYPE_COMBO, 'prohibited_unless:items.*.type,'.OrderItem::TYPE_COMBO, 'array', 'min:1', 'max:50'],
            'items.*.product_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.product_ids.required_if' => 'Select at least one product from the combo.',
            'items.*.product_ids.min' => 'Select at least one product from the combo.',
        ];
    }
}
