<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.manage');
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['sometimes', 'nullable', 'confirmed', Password::defaults()],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var User $target */
            $target = $this->route('user');
            $actor = $this->user();

            if (! $actor->isSuperadmin()) {
                if ($this->has('roles') && in_array(Role::SUPERADMIN, (array) $this->input('roles', []), true)) {
                    $validator->errors()->add('roles', 'You are not allowed to assign the superadmin role.');
                }

                if ($target->isSuperadmin()) {
                    $validator->errors()->add('user', 'Only a superadmin can modify a superadmin account.');
                }
            }

            if ($actor->is($target) && $this->input('status') === User::STATUS_INACTIVE) {
                $validator->errors()->add('status', 'You cannot deactivate your own account.');
            }
        });
    }
}
