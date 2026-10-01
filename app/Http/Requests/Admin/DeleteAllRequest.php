<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by every admin "Delete All" endpoint. The route's `permission:` and
 * `role:` middleware decide who may call it; this adds the second guard
 * (admin / superadmin only) and requires the confirmation phrase the admin UI
 * only sends from its confirmation dialog — so a bare DELETE never wipes data.
 */
class DeleteAllRequest extends FormRequest
{
    public const CONFIRMATION = 'DELETE ALL';

    public function authorize(): bool
    {
        return $this->user()->hasAnyRole([Role::SUPERADMIN, Role::ADMIN]);
    }

    public function rules(): array
    {
        return [
            'confirm' => ['required', 'string', Rule::in([self::CONFIRMATION])],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm.required' => 'Confirm this action before deleting everything.',
            'confirm.in' => 'Confirm this action before deleting everything.',
        ];
    }
}
