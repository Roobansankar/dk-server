<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Production-safe. Creates the first superadmin from ADMIN_EMAIL / ADMIN_PASSWORD.
 * Skipped (with a notice) when those env vars are not set.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('salon.admin.email');
        $password = config('salon.admin.password');

        if (! $email || ! $password) {
            $this->command?->warn('AdminUserSeeder skipped: set ADMIN_EMAIL and ADMIN_PASSWORD to create the first superadmin.');

            return;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => config('salon.admin.name', 'Super Admin'),
                'password' => Hash::make($password),
                'status' => User::STATUS_ACTIVE,
            ],
        );

        if (! $user->hasRole(Role::SUPERADMIN)) {
            $user->assignRole(Role::SUPERADMIN);
        }

        $this->command?->info("Superadmin ready: {$email}");
    }
}
