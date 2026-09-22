<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        if (! $user->can('users.manage')) {
            return false;
        }

        // Only a superadmin may edit another superadmin.
        if ($model->isSuperadmin() && ! $user->isSuperadmin()) {
            return false;
        }

        return true;
    }

    public function delete(User $user, User $model): bool
    {
        if (! $user->can('users.manage') || $user->is($model)) {
            return false;
        }

        if ($model->isSuperadmin() && ! $user->isSuperadmin()) {
            return false;
        }

        return true;
    }
}
