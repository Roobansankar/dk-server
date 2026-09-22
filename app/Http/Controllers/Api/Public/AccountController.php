<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\UserResource;
use App\Models\Appointment;
use App\Support\AppointmentFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    /**
     * Only name/email/phone are ever validated by UpdateProfileRequest — see
     * its docblock. `type`/`status`/`google_id`/roles cannot be changed here
     * even if sent, because they simply aren't part of the validated set.
     */
    public function update(UpdateProfileRequest $request): UserResource
    {
        $request->user()->update($request->safe()->only(['name', 'email', 'phone']));

        return new UserResource($request->user());
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update([
            'password' => Hash::make($request->string('password')),
        ]);

        // Revoke every other session/token for this account — keep only the
        // one used for this request, so this device stays signed in.
        // `currentAccessToken()` is only set when the request actually came
        // in via a Sanctum bearer token; guard the (rare) case it's absent
        // rather than revoke every token including the caller's own.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        return response()->json(['message' => 'Your password has been updated.']);
    }

    /**
     * The authenticated customer's own appointment history. Ownership is
     * always derived from the bearer token (`$request->user()->id`) — never
     * from a request parameter — so one customer can never see another's
     * appointments by guessing an id or crafting a query string.
     */
    public function appointments(Request $request)
    {
        $request->validate(AppointmentFilters::rules());

        $query = AppointmentFilters::apply(
            Appointment::query()->forUser($request->user()->id),
            $request
        );

        $appointments = $query
            ->orderByDesc('appointment_date')
            ->orderByDesc('appointment_time')
            ->paginate($request->integer('per_page', 20));

        return AppointmentResource::collection($appointments);
    }
}
