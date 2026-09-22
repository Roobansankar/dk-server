<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_admin_endpoints_reject_guests(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->getJson('/api/admin/service-categories')->assertUnauthorized();
    }

    public function test_plain_admin_cannot_access_user_management(): void
    {
        $this->actingAsToken($this->admin());

        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson('/api/admin/roles', ['name' => 'x'])->assertForbidden();
    }

    public function test_plain_admin_can_view_services_but_not_missing_permissions(): void
    {
        $user = $this->userWith(['services.view']);
        $this->actingAsToken($user);

        $this->getJson('/api/admin/service-categories')->assertOk();
        $this->postJson('/api/admin/service-categories', [
            'gender' => 'male', 'name' => 'Hair',
        ])->assertForbidden();
    }

    public function test_superadmin_passes_every_permission_check(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->getJson('/api/admin/dashboard')->assertOk();
        $this->getJson('/api/admin/users')->assertOk();
        $this->getJson('/api/admin/roles')->assertOk();
        $this->getJson('/api/admin/site-settings')->assertOk();
    }

    public function test_non_superadmin_cannot_assign_superadmin_role(): void
    {
        $this->actingAsToken($this->userWith(['users.view', 'users.manage']));

        $this->postJson('/api/admin/users', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => ['superadmin'],
        ])->assertStatus(422);
    }
}
