<?php

namespace App\Http\Requests\Admin;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.manage');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(Order::ADMIN_ACTIONS)],
        ];
    }
}
