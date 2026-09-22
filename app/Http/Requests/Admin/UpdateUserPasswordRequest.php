<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * A staff member (admin or superadmin) resetting another account's password
 * — staff or customer, no current-password required (see
 * UserController::updatePassword). Deliberately gated on `isStaff()` rather
 * than the `users.manage` permission: `admin` doesn't hold that permission
 * (RolePermissionSeeder excludes all `users.*` from it) but must still be
 * able to reset another admin's or a customer's password, per the app's
 * password policy — the superadmin-target block below is what keeps that
 * safe, not the permission system.
 */
class UpdateUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isStaff();
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /** Same rule as UpdateUserRequest's "modify a superadmin" check — kept a 422 for consistency with that sibling. */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $actor = $this->user();
            /** @var User $target */
            $target = $this->route('user');

            if ($actor->is($target)) {
                $validator->errors()->add(
                    'user',
                    'Use "Change password" in your account menu to change your own password.',
                );

                return;
            }

            if ($target->isSuperadmin() && ! $actor->isSuperadmin()) {
                $validator->errors()->add('user', 'Only a superadmin can change a superadmin’s password.');
            }
        });
    }
}
