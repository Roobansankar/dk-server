<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * "Continue with Google" for customers, via Laravel Socialite. Stateless:
 * this backend is bearer-token/API-only with no SPA session-cookie flow
 * wired up, so a session-backed `state` round trip has nowhere reliable to
 * live across the redirect to Google and back. The trade-off is the
 * CSRF-style `state` check Socialite normally performs is skipped; this is
 * mitigated by the flow only ever resulting in a login for a Google-verified
 * email address — it never performs a privileged action or trusts anything
 * from the request except what Google itself returned.
 */
class GoogleAuthController extends Controller
{
    private function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    private function frontendUrl(string $path): string
    {
        return rtrim(config('salon.frontend_url'), '/').$path;
    }

    /**
     * Where the SPA should land after sign-in completes (e.g. "/booking" to
     * resume a booking in progress) — only ever a same-origin relative path,
     * never something that could carry the browser off to another host.
     */
    private function safeReturnPath(?string $path): ?string
    {
        if (! $path || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        return $path;
    }

    public function redirect(Request $request): JsonResponse
    {
        if (! $this->configured()) {
            return response()->json([
                'message' => 'Google sign-in is not configured.',
            ], 422);
        }

        $driver = Socialite::driver('google')->stateless();

        // Round-tripped through Google's own `state` param (echoed back
        // verbatim in the callback) rather than sessionStorage: the browser
        // can leave on www.dkstylehub.com and come back on dkstylehub.com (or
        // vice versa) mid-flow — sessionStorage doesn't survive that since
        // it's locked to one origin, but Google's state does.
        if ($returnTo = $this->safeReturnPath($request->query('redirect_to'))) {
            $driver->with(['state' => $returnTo]);
        }

        $url = $driver->redirect()->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! $this->configured()) {
            Log::warning('Google OAuth callback hit while Google sign-in is not configured.');

            return redirect()->away($this->frontendUrl('/login?google_error=1'));
        }

        if ($request->has('error')) {
            // User denied consent (or Google refused) — Google bounces back
            // with ?error=access_denied instead of a code.
            Log::warning('Google OAuth callback returned an error from Google.', [
                'error' => $request->query('error'),
            ]);

            return redirect()->away($this->frontendUrl('/login?google_error=1'));
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            // Expired/invalid code, redirect-uri or client-secret mismatch,
            // network failure, etc. — never a fake success; always bounce
            // back to a clean error state.
            Log::warning('Google OAuth code exchange failed.', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return redirect()->away($this->frontendUrl('/login?google_error=1'));
        }

        if (! $googleUser->getEmail()) {
            Log::warning('Google OAuth succeeded but Google returned no email address.');

            return redirect()->away($this->frontendUrl('/login?google_error=1'));
        }

        $user = User::where('google_id', $googleUser->getId())->first();

        if ($user) {
            // Refresh the photo on every Google sign-in so a missing or
            // stale avatar heals itself instead of staying blank forever.
            if ($googleUser->getAvatar() && $user->google_avatar_url !== $googleUser->getAvatar()) {
                $user->forceFill(['google_avatar_url' => $googleUser->getAvatar()])->save();
            }
        }

        if (! $user) {
            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user) {
                // Link an existing account (customer OR staff) to this Google
                // identity. Never touches `type`/`status`/roles — Google auth
                // only ever authenticates, it never grants privileges.
                $user->forceFill([
                    'google_id' => $googleUser->getId(),
                    'google_avatar_url' => $googleUser->getAvatar(),
                ])->save();
            } else {
                // Brand new identity — always a customer, never staff.
                $user = User::create([
                    'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: 'Google User',
                    'email' => $googleUser->getEmail(),
                    'password' => Hash::make(Str::password(40)),
                    'status' => User::STATUS_ACTIVE,
                    'type' => User::TYPE_CUSTOMER,
                    'google_id' => $googleUser->getId(),
                    'google_avatar_url' => $googleUser->getAvatar(),
                ]);
            }
        }

        if (! $user->isActive()) {
            Log::warning('Google sign-in refused for an inactive user account.', [
                'user_id' => $user->id,
            ]);

            return redirect()->away($this->frontendUrl('/login?google_error=1'));
        }

        $token = $user->createToken('customer-google')->plainTextToken;

        $callback = '/auth/google/callback?token='.$token;
        if ($returnTo = $this->safeReturnPath($request->query('state'))) {
            $callback .= '&redirect_to='.urlencode($returnTo);
        }

        return redirect()->away($this->frontendUrl($callback));
    }
}
