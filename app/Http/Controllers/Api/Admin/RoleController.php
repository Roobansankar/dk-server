<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return RoleResource::collection($roles);
    }

    public function store(StoreRoleRequest $request)
    {
        $role = Role::create(['name' => $request->string('name')->value(), 'guard_name' => 'web']);

        if ($request->has('permissions')) {
            $role->syncPermissions($request->array('permissions'));
        }

        return (new RoleResource($role->load('permissions')))->response()->setStatusCode(201);
    }

    public function show(Role $role)
    {
        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        if ($request->filled('name') && ! $role->isProtected()) {
            $role->update(['name' => $request->string('name')->value()]);
        }

        if ($request->has('permissions') && ! $role->isProtected()) {
            $role->syncPermissions($request->array('permissions'));
        }

        return new RoleResource($role->load('permissions'));
    }

    public function destroy(Role $role)
    {
        if ($role->isProtected()) {
            return response()->json(['message' => 'This role is protected and cannot be deleted.'], 422);
        }

        if ($role->users()->exists()) {
            return response()->json(['message' => 'This role is still assigned to users. Reassign them first.'], 422);
        }

        $role->delete();

        return response()->noContent();
    }
}
