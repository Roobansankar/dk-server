<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_a_visitor_can_register_a_customer_account(): void
    {
        $response = $this->postJson('/api/account/register', [
            'name' => 'Priya Shah',
            'email' => 'priya@example.com',
            'phone' => '+91 9876543210',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.type', User::TYPE_CUSTOMER)
            ->assertJsonPath('user.email', 'priya@example.com')
            ->assertJsonMissingPath('user.password');
        $this->assertNotEmpty($response->json('token'));

        $this->assertDatabaseHas('users', [
            'email' => 'priya@example.com',
            'type' => User::TYPE_CUSTOMER,
        ]);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        $this->customer(['email' => 'dupe@example.com']);

        $this->postJson('/api/account/register', [
            'name' => 'Someone',
            'email' => 'dupe@example.com',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_registration_requires_a_confirmed_password(): void
    {
        $this->postJson('/api/account/register', [
            'name' => 'Someone',
            'email' => 'new@example.com',
            'password' => 'correct-horse',
            'password_confirmation' => 'does-not-match',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_customer_can_log_in_and_receive_a_token(): void
    {
        $user = $this->customer(['password' => Hash::make('secret-pass')]);

        $response = $this->postJson('/api/account/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email', 'type']]);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = $this->customer(['password' => Hash::make('secret-pass')]);

        $this->postJson('/api/account/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    public function test_a_staff_account_can_also_use_the_customer_login_endpoint(): void
    {
        // Sanctum tokens are guard-agnostic in this app — a staff member can
        // still authenticate through the customer-facing endpoint (e.g. to
        // book for themselves on the public site). It grants no admin access
        // through this door; roles/permissions are unaffected either way.
        $user = $this->admin();
        $user->update(['password' => Hash::make('secret-pass')]);

        $this->postJson('/api/account/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ])->assertOk();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/account/me')->assertUnauthorized();
    }

    public function test_me_returns_the_current_customer(): void
    {
        $user = $this->actingAsToken($this->customer());

        $this->getJson('/api/account/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.type', User::TYPE_CUSTOMER)
            ->assertJsonMissingPath('data.password');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->customer();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/account/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/account/me')->assertUnauthorized();
    }

    public function test_forgot_password_returns_a_generic_message_regardless_of_whether_the_email_exists(): void
    {
        $this->customer(['email' => 'exists@example.com']);

        $forExisting = $this->postJson('/api/account/forgot-password', ['email' => 'exists@example.com']);
        $forMissing = $this->postJson('/api/account/forgot-password', ['email' => 'nobody@example.com']);

        $forExisting->assertOk();
        $forMissing->assertOk();
        $this->assertSame($forExisting->json('message'), $forMissing->json('message'));
    }

    public function test_forgot_password_dispatches_a_real_reset_notification_with_a_working_token(): void
    {
        Notification::fake();
        $user = $this->customer(['email' => 'reset-me@example.com']);

        $this->postJson('/api/account/forgot-password', ['email' => $user->email])->assertOk();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

        $capturedToken = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$capturedToken) {
            $capturedToken = $notification->token;

            return true;
        });
        $this->assertNotEmpty($capturedToken);

        // The reset link points at the React SPA, not a nonexistent
        // `password.reset` web route (see AppServiceProvider::boot()).
        $mailMessage = (new ResetPassword($capturedToken))->toMail($user);
        $this->assertStringContainsString('/reset-password?token=', $mailMessage->actionUrl);
        $this->assertStringContainsString(urlencode($user->email), $mailMessage->actionUrl);

        // Completing the reset with that real token actually works end-to-end.
        $this->postJson('/api/account/reset-password', [
            'token' => $capturedToken,
            'email' => $user->email,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->postJson('/api/account/login', [
            'email' => $user->email,
            'password' => 'brand-new-pass',
        ])->assertOk();
    }

    public function test_reset_password_rejects_an_invalid_token(): void
    {
        $user = $this->customer();

        $this->postJson('/api/account/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertStatus(422);
    }
}
