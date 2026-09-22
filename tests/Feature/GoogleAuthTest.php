<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

/**
 * Covers everything short of a real human clicking "Allow" on Google's own
 * consent screen (which needs a live browser + a real Google account — see
 * the manual verification step). What's testable in-process: the redirect
 * URL is genuinely built for Google (not a stub) when configured, the
 * "not configured" error path, and the account find/link/create rules using
 * Socialite's own official testing fake (Laravel\Socialite\Testing\*).
 */
class GoogleAuthTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['salon.frontend_url' => 'http://localhost:5175']);
    }

    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'fake-client-id.apps.googleusercontent.com',
            'services.google.client_secret' => 'fake-client-secret',
            'services.google.redirect' => 'http://localhost:8000/api/account/google/callback',
        ]);
    }

    public function test_redirect_reports_not_configured_when_credentials_are_blank(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->getJson('/api/account/google/redirect')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Google sign-in is not configured.');
    }

    public function test_redirect_returns_a_real_google_authorization_url_when_configured(): void
    {
        $this->configureGoogle();

        $response = $this->getJson('/api/account/google/redirect')->assertOk();
        $url = $response->json('url');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth', $url);
        $this->assertStringContainsString('client_id=fake-client-id.apps.googleusercontent.com', $url);
        $this->assertStringContainsString(urlencode('http://localhost:8000/api/account/google/callback'), $url);
    }

    public function test_callback_creates_a_new_customer_account_for_a_brand_new_google_identity(): void
    {
        $this->configureGoogle();
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-new-1',
            'name' => 'New Googler',
            'email' => 'newgoogler@example.com',
        ]));

        $response = $this->get('/api/account/google/callback');

        $response->assertRedirect();
        $this->assertStringStartsWith('http://localhost:5175/auth/google/callback?token=', $response->headers->get('Location'));

        $user = User::where('email', 'newgoogler@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame(User::TYPE_CUSTOMER, $user->type);
        $this->assertSame('google-new-1', $user->google_id);
        $this->assertSame(0, $user->roles()->count());
    }

    public function test_callback_logs_in_an_existing_account_matched_by_google_id_without_duplicating(): void
    {
        $this->configureGoogle();
        $existing = $this->customer(['email' => 'already@example.com', 'google_id' => 'google-existing-1']);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-existing-1',
            'email' => 'already@example.com',
        ]));

        $this->get('/api/account/google/callback')->assertRedirect();

        $this->assertSame(1, User::where('email', 'already@example.com')->count());
        $this->assertSame(1, User::where('google_id', 'google-existing-1')->count());
    }

    public function test_callback_links_an_existing_email_matched_account_instead_of_duplicating(): void
    {
        $this->configureGoogle();
        $existing = $this->customer(['email' => 'linkme@example.com']);
        $this->assertNull($existing->google_id);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-link-1',
            'email' => 'linkme@example.com',
        ]));

        $this->get('/api/account/google/callback')->assertRedirect();

        $this->assertSame(1, User::where('email', 'linkme@example.com')->count());
        $existing->refresh();
        $this->assertSame('google-link-1', $existing->google_id);
        $this->assertSame(User::TYPE_CUSTOMER, $existing->type); // untouched
    }

    public function test_linking_never_grants_staff_privileges_and_staff_stay_staff(): void
    {
        $this->configureGoogle();
        $staff = $this->admin();
        $staff->update(['email' => 'staffmember@example.com']);
        $this->assertTrue($staff->hasRole(Role::ADMIN));

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-staff-1',
            'email' => 'staffmember@example.com',
        ]));

        $this->get('/api/account/google/callback')->assertRedirect();

        $staff->refresh();
        // Still staff, still holds the same role — Google auth only linked
        // the identity, it never touched type/roles/permissions.
        $this->assertSame(User::TYPE_STAFF, $staff->type);
        $this->assertTrue($staff->hasRole(Role::ADMIN));
        $this->assertSame('google-staff-1', $staff->google_id);
    }

    public function test_a_denied_or_failed_google_exchange_redirects_to_a_clean_error_not_a_fake_success(): void
    {
        $this->configureGoogle();
        Socialite::fake('google', function () {
            throw new \Exception('user denied access');
        });

        $response = $this->get('/api/account/google/callback');

        $response->assertRedirect('http://localhost:5175/login?google_error=1');
        $this->assertSame(0, User::count());
    }

    public function test_callback_reports_not_configured_cleanly_instead_of_crashing(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $response = $this->get('/api/account/google/callback');

        $response->assertRedirect('http://localhost:5175/login?google_error=1');
    }
}
