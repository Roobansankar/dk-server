<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserPasswordRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->staff()
            ->when(! $request->user()->isSuperadmin(), fn ($q) => $q->whereDoesntHave(
                'roles',
                fn ($r) => $r->where('name', Role::SUPERADMIN),
            ))
            ->with('roles')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($sub) => $sub->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('role'), fn ($q) => $q->role($request->string('role')->value()))
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request)
    {
        $user = User::create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => Hash::make($request->string('password')),
            'status' => $request->input('status', User::STATUS_ACTIVE),
            'type' => User::TYPE_STAFF,
        ]);

        if ($request->has('roles')) {
            $user->syncRoles($request->array('roles'));
        }

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    // Staff-only: this screen manages admin-panel accounts and their roles.
    // Customer accounts (self-registered / Google) are out of its scope, so a
    // guessed customer id 404s here exactly as if the row didn't exist. A
    // superadmin target is hidden the same way from a non-superadmin actor —
    // same 404, not 403, so a guessed id doesn't even confirm the account
    // exists (mirrors index()'s query-level exclusion).
    public function show(Request $request, User $user)
    {
        abort_unless($user->isStaff(), 404);
        abort_if($user->isSuperadmin() && ! $request->user()->isSuperadmin(), 404);

        return new UserResource($user->load('roles'));
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        abort_unless($user->isStaff(), 404);

        $data = $request->safe()->only(['name', 'email', 'status']);
        $changingPassword = $request->filled('password');

        if ($changingPassword) {
            $data['password'] = Hash::make($request->string('password'));
        }

        $user->update($data);

        if ($request->has('roles')) {
            $user->syncRoles($request->array('roles'));
        }

        // A password set here (superadmin editing another staff member
        // through the full user-edit form) revokes every one of that
        // account's sessions/tokens, same as the dedicated endpoint below.
        if ($changingPassword) {
            $user->tokens()->delete();
        }

        return new UserResource($user->load('roles'));
    }

    /**
     * Any staff member (admin or superadmin) resetting another account's
     * password — staff or customer, no current-password required. The
     * superadmin-target block (and the self-service redirect) lives in
     * UpdateUserPasswordRequest, not a permission check: `admin` doesn't
     * hold `users.manage`, so this route sits outside that gate (see
     * routes/api.php) and relies entirely on the request's own
     * authorization for the "never touch a superadmin" rule.
     */
    public function updatePassword(UpdateUserPasswordRequest $request, User $user): JsonResponse
    {
        $user->update(['password' => Hash::make($request->string('password'))]);

        // No "current" token to preserve — the actor and target are
        // different accounts, so every session on the target is revoked.
        $user->tokens()->delete();

        return response()->json(['message' => 'Password updated.']);
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless($user->isStaff(), 404);

        if ($request->user()->is($user)) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        if ($user->isSuperadmin() && ! $request->user()->isSuperadmin()) {
            return response()->json(['message' => 'Only a superadmin can delete a superadmin account.'], 403);
        }

        if ($user->isSuperadmin() && User::role(Role::SUPERADMIN)->count() <= 1) {
            return response()->json(['message' => 'The last superadmin cannot be deleted.'], 422);
        }

        $user->delete();

        return response()->noContent();
    }
}
