<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Production-safe. Creates the permission catalogue and the two built-in roles.
 * Idempotent — safe to re-run after adding new permissions.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('permission:cache-reset');

        foreach (Permission::all_names() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Superadmin: bypasses every check via Gate::before, but we still grant
        // all permissions so the relationship is explicit.
        $superadmin = Role::findOrCreate(Role::SUPERADMIN, 'web');
        $superadmin->syncPermissions(Permission::all_names());

        // Admin: everything except user/role/permission management — a
        // deliberate, tested boundary (see AuthorizationTest::
        // test_plain_admin_cannot_access_user_management). Admin still
        // resets non-superadmin passwords via the dedicated password
        // endpoint (UserController::updatePassword), which is intentionally
        // NOT gated by users.* — see routes/api.php.
        $admin = Role::findOrCreate(Role::ADMIN, 'web');
        $admin->syncPermissions(array_values(array_filter(
            Permission::all_names(),
            fn ($p) => ! str_starts_with($p, 'users.') && ! str_starts_with($p, 'roles.'),
        )));

        Artisan::call('permission:cache-reset');
    }
}
