<?php

namespace App\Http\Requests\Admin;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('roles.manage');
    }

    public function rules(): array
    {
        $id = $this->route('role')->id;

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:60', 'regex:/^[a-z0-9 _-]+$/i',
                Rule::unique('roles', 'name')->ignore($id),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::all_names())],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var Role $role */
            $role = $this->route('role');

            if ($role->name === Role::SUPERADMIN) {
                if ($this->has('name') && $this->input('name') !== Role::SUPERADMIN) {
                    $validator->errors()->add('name', 'The superadmin role cannot be renamed.');
                }
                if ($this->has('permissions')) {
                    $validator->errors()->add('permissions', 'The superadmin role always has every permission.');
                }
            }
        });
    }
}
