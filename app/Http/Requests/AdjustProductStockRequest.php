<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustProductStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => [
                'required',
                'integer',
                'not_in:0',
                'between:-1000000,1000000',
            ],
            'reason' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }
}
