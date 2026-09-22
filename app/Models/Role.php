<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class Role extends SpatieRole
{
    /** Roles that must never be deleted or stripped of privileges. */
    public const SUPERADMIN = 'superadmin';

    public const ADMIN = 'admin';

    public const PROTECTED = [self::SUPERADMIN];

    public function isProtected(): bool
    {
        return in_array($this->name, self::PROTECTED, true);
    }

    /**
     * Override spatie's users() relation to pin the related model.
     *
     * This app uses a single guard ("web"). Sanctum's auth middleware swaps the
     * default guard to "sanctum" (which has no provider), which otherwise breaks
     * spatie's guard-derived model resolution during withCount('users') etc.
     */
    public function users(): BelongsToMany
    {
        return $this->morphedByMany(
            User::class,
            'model',
            config('permission.table_names.model_has_roles'),
            app(PermissionRegistrar::class)->pivotRole,
            config('permission.column_names.model_morph_key'),
        );
    }
}
