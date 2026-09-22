<?php

namespace App\Http\Requests\Admin;

use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('roles.manage');
    }

    public function rules(): array
    {
        $id = $this->route('role')?->id;

        return [
            'name' => [
                'required', 'string', 'max:60', 'regex:/^[a-z0-9 _-]+$/i',
                Rule::unique('roles', 'name')->ignore($id),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::all_names())],
        ];
    }
}
