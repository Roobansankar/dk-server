<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
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

    public function redirect(): JsonResponse
    {
        if (! $this->configured()) {
            return response()->json([
                'message' => 'Google sign-in is not configured.',
            ], 422);
        }

        $url = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    public function callback(): RedirectResponse
    {
        if (! $this->configured()) {
            return redirect()->away($this->frontendUrl('/login?google_error=1'));
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable) {
            // Denied consent, expired/invalid code, network failure, etc. —
            // never a fake success; always bounce back to a clean error state.
            return redirect()->away($this->frontendUrl('/login?google_error=1'));
        }

        if (! $googleUser->getEmail()) {
            return redirect()->away($this->frontendUrl('/login?google_error=1'));
        }

        $user = User::where('google_id', $googleUser->getId())->first();

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
            return redirect()->away($this->frontendUrl('/login?google_error=1'));
        }

        $token = $user->createToken('customer-google')->plainTextToken;

        return redirect()->away($this->frontendUrl('/auth/google/callback?token='.$token));
    }
}
