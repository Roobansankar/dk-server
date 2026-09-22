<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_superadmin_can_create_activate_and_deactivate_users(): void
    {
        $this->actingAsToken($this->superadmin());

        $created = $this->postJson('/api/admin/users', [
            'name' => 'Nadia',
            'email' => 'nadia@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => ['admin'],
        ])->assertCreated()->assertJsonFragment(['admin']);

        $id = $created->json('data.id');

        $this->patchJson("/api/admin/users/{$id}", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');
    }

    public function test_passwords_are_hashed_and_never_returned(): void
    {
        $this->actingAsToken($this->superadmin());

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Nadia',
            'email' => 'nadia@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $response->assertJsonMissingPath('data.password');
        $user = User::where('email', 'nadia@example.com')->first();
        $this->assertNotEquals('password123', $user->password);
        $this->assertTrue(password_verify('password123', $user->password));
    }

    public function test_a_user_cannot_deactivate_their_own_account(): void
    {
        $me = $this->superadmin();
        $this->actingAsToken($me);

        $this->patchJson("/api/admin/users/{$me->id}", ['status' => 'inactive'])
            ->assertStatus(422);
    }

    public function test_a_non_superadmin_cannot_delete_a_superadmin(): void
    {
        $target = $this->superadmin();
        $this->actingAsToken($this->userWith(['users.view', 'users.manage']));

        $this->deleteJson("/api/admin/users/{$target->id}")->assertStatus(403);
    }
}
