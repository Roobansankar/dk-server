<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_superadmin_can_create_a_role_with_permissions(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/roles', [
            'name' => 'receptionist',
            'permissions' => ['appointments.view', 'appointments.manage'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'receptionist')
            ->assertJsonFragment(['appointments.view']);
    }

    public function test_unknown_permission_is_rejected(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/roles', [
            'name' => 'bad',
            'permissions' => ['appointments.delete_everything'],
        ])->assertStatus(422);
    }

    public function test_superadmin_role_cannot_be_edited_or_deleted(): void
    {
        $this->actingAsToken($this->superadmin());
        $role = Role::findByName(Role::SUPERADMIN, 'web');

        $this->patchJson("/api/admin/roles/{$role->id}", ['permissions' => []])->assertStatus(422);
        $this->deleteJson("/api/admin/roles/{$role->id}")->assertStatus(422);
    }

    public function test_role_assigned_to_users_cannot_be_deleted(): void
    {
        $this->actingAsToken($this->superadmin());
        $role = Role::findOrCreate('temp', 'web');
        User::factory()->create()->assignRole($role);

        $this->deleteJson("/api/admin/roles/{$role->id}")->assertStatus(422);
    }

    public function test_permissions_catalogue_is_available(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->getJson('/api/admin/permissions')
            ->assertOk()
            ->assertJsonStructure(['data' => ['groups', 'all']]);
    }
}
