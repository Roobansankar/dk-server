<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_a_plain_admin_can_log_in_and_receive_a_token(): void
    {
        $user = $this->admin();
        $user->update(['password' => bcrypt('secret-pass')]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ])->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $response->assertJsonPath('user.roles.0', 'admin');

        // The token works on the staff session endpoints.
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_a_legacy_customer_cannot_log_in_through_the_staff_endpoint(): void
    {
        $customer = $this->customer(['password' => bcrypt('secret-pass')]);

        // Correct password, active account — still refused, with the same
        // generic error as a wrong password.
        $this->postJson('/api/auth/login', [
            'email' => $customer->email,
            'password' => 'secret-pass',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', __('auth.failed'))
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** `type` decides it — a role on a customer row doesn't make it a staff login. */
    public function test_a_customer_row_holding_a_staff_role_still_cannot_log_in(): void
    {
        $this->seedRoles();
        $customer = $this->customer(['password' => bcrypt('secret-pass')]);
        $customer->assignRole('admin');

        $this->postJson('/api/auth/login', [
            'email' => $customer->email,
            'password' => 'secret-pass',
        ])->assertStatus(422)->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** A token issued before customer sign-in was removed is not a usable session. */
    public function test_a_leftover_customer_token_cannot_use_the_staff_session_endpoints(): void
    {
        $this->seedRoles();
        $customer = $this->customer(['password' => bcrypt('old-secret-pass')]);
        $token = $customer->createToken('customer-web')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->withHeaders($headers)->getJson('/api/auth/me')->assertForbidden();

        $this->withHeaders($headers)->putJson('/api/auth/password', [
            'current_password' => 'old-secret-pass',
            'password' => 'New-secret-pass-123',
            'password_confirmation' => 'New-secret-pass-123',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('old-secret-pass', $customer->fresh()->password));

        // ...nor reach anything in the admin API.
        $this->withHeaders($headers)->getJson('/api/admin/dashboard')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/admin/users')->assertForbidden();
        $this->withHeaders($headers)->putJson("/api/admin/users/{$customer->id}/password", [
            'password' => 'New-secret-pass-123',
            'password_confirmation' => 'New-secret-pass-123',
        ])->assertForbidden();
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

    public function test_a_staff_member_can_change_their_own_password(): void
    {
        $user = $this->admin();
        $user->update(['password' => bcrypt('old-secret-pass')]);
        $token = $user->createToken('admin')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/password', [
                'current_password' => 'wrong-password',
                'password' => 'New-secret-pass-123',
                'password_confirmation' => 'New-secret-pass-123',
            ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/password', [
                'current_password' => 'old-secret-pass',
                'password' => 'New-secret-pass-123',
                'password_confirmation' => 'New-secret-pass-123',
            ])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'New-secret-pass-123',
        ])->assertOk();
    }

    public function test_a_superadmin_can_reset_another_staff_members_password(): void
    {
        $target = $this->admin();
        $this->actingAsToken($this->superadmin());

        $this->putJson("/api/admin/users/{$target->id}/password", [
            'password' => 'Reset-secret-pass-123',
            'password_confirmation' => 'Reset-secret-pass-123',
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', [
            'email' => $target->email,
            'password' => 'Reset-secret-pass-123',
        ])->assertOk();
    }

    public function test_changing_a_password_requires_authentication(): void
    {
        $target = $this->admin();

        $this->putJson('/api/auth/password', [])->assertUnauthorized();
        $this->putJson("/api/admin/users/{$target->id}/password", [])->assertUnauthorized();
    }

    /** Customer accounts were removed — none of their endpoints exist any more. */
    public function test_customer_account_endpoints_no_longer_exist(): void
    {
        $this->postJson('/api/account/register', [])->assertNotFound();
        $this->postJson('/api/account/login', [])->assertNotFound();
        $this->postJson('/api/account/logout')->assertNotFound();
        $this->getJson('/api/account/me')->assertNotFound();
        $this->postJson('/api/account/forgot-password', [])->assertNotFound();
        $this->postJson('/api/account/reset-password', [])->assertNotFound();
        $this->getJson('/api/account/google/redirect')->assertNotFound();
        $this->getJson('/api/account/google/callback')->assertNotFound();
        $this->putJson('/api/account/profile', [])->assertNotFound();
        $this->putJson('/api/account/password', [])->assertNotFound();
        $this->getJson('/api/account/appointments')->assertNotFound();
        $this->getJson('/api/account/orders')->assertNotFound();
    }
}
