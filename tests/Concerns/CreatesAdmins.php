<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

trait CreatesAdmins
{
    protected function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    protected function superadmin(): User
    {
        $this->seedRoles();

        return User::factory()->create()->assignRole(Role::SUPERADMIN);
    }

    protected function admin(): User
    {
        $this->seedRoles();

        return User::factory()->create()->assignRole(Role::ADMIN);
    }

    /** A user with only the given permissions and no role. */
    protected function userWith(array $permissions): User
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    protected function actingAsToken(User $user): User
    {
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    protected function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['type' => User::TYPE_CUSTOMER], $attributes));
    }
}
