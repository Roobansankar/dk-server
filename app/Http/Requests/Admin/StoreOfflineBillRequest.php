<?php

namespace App\Http\Requests\Admin;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An in-person product sale billed by staff (Admin → Offline Billing). Only
 * *what* was bought and how it was paid are accepted — prices, tax and totals
 * come from App\Support\OrderPricing, and stock is re-checked under lock by
 * ProductInventoryService::recordSale().
 *
 * The preview endpoint validates only the `items` part of these rules.
 */
class StoreOfflineBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.manage');
    }

    public static function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            // One row per product — the UI merges repeats into one quantity.
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }

    public function rules(): array
    {
        return [
            ...self::itemRules(),
            'customer_name' => ['required', 'string', 'max:120'],
            // Optional for a walk-in; when given it must look like a phone number.
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'payment_method' => ['required', 'string', Rule::in(Order::PAYMENT_METHODS)],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.product_id.distinct' => 'This product is already on the bill.',
        ];
    }
}
