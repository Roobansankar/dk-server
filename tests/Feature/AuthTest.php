<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_admin_can_log_in_and_receive_a_token(): void
    {
        $user = $this->superadmin();
        $user->update(['password' => bcrypt('secret-pass')]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'roles']]);
        $this->assertNotEmpty($response->json('token'));
        $response->assertJsonMissingPath('user.password');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = $this->admin();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'nope',
        ])->assertStatus(422);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = $this->admin();
        $user->update(['status' => User::STATUS_INACTIVE, 'password' => bcrypt('secret-pass')]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ])->assertStatus(422);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_current_user_with_permissions(): void
    {
        $user = $this->actingAsToken($this->superadmin());

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->superadmin();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Simulate a fresh request (guards memoise the resolved user per app instance).
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')->assertUnauthorized();
    }
}
