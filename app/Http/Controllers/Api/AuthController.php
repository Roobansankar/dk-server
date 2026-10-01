<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        // Staff only. A legacy customer row (customer accounts were removed)
        // is refused with the same generic message as a wrong password, so
        // this endpoint can't be used to sign in as — or probe for — one.
        if (! $user || ! $user->isStaff() || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated.',
            ]);
        }

        $token = $user->createToken(
            $request->string('device_name')->value() ?: 'admin',
            ['*']
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        $this->ensureStaff($request);

        return new UserResource($request->user()->load('roles'));
    }

    /**
     * Self password change for the signed-in staff member (superadmin or
     * admin) — current password required (see UpdatePasswordRequest). Every
     * other token for this account is revoked so other signed-in devices
     * are logged out; the token used for this request is left alone so the
     * admin isn't booted from their own session right after changing it.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $this->ensureStaff($request);

        $user = $request->user();
        $user->update(['password' => Hash::make($request->string('password'))]);

        // `currentAccessToken()` is only set when the request came in via a
        // Sanctum bearer token; guard the (rare) case it's absent rather
        // than revoke every token including the caller's own.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        return response()->json(['message' => 'Your password has been updated.']);
    }

    /**
     * A still-unexpired token issued to a legacy customer account (before
     * customer sign-in was removed) must not work as a session here.
     */
    private function ensureStaff(Request $request): void
    {
        abort_unless($request->user()->isStaff(), 403, 'This action is unauthorized.');
    }
}
