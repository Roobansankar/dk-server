<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\Role;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('appointments.view');
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->can('appointments.view');
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $user->can('appointments.manage');
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $user->can('appointments.manage');
    }

    /** Bulk "Delete All" — the admin role only (superadmin passes via Gate::before). */
    public function deleteAny(User $user): bool
    {
        return $user->hasRole(Role::ADMIN) && $user->can('appointments.manage');
    }
}
