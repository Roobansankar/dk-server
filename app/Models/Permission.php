<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    /**
     * Canonical permission catalogue. Grouped for the admin UI; the flat list
     * is what actually gets seeded and checked.
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        'dashboard' => ['dashboard.view'],
        'appointments' => ['appointments.view', 'appointments.manage', 'appointments.offline'],
        'payments' => ['payments.view', 'payments.export'],
        'services' => ['services.view', 'services.create', 'services.update', 'services.delete'],
        'products' => ['products.view', 'products.create', 'products.update', 'products.delete'],
        'orders' => ['orders.view', 'orders.manage'],
        'stylists' => ['stylists.view', 'stylists.manage'],
        'pricing' => ['pricing.view', 'pricing.manage'],
        'gallery' => ['gallery.view', 'gallery.manage'],
        'videos' => ['videos.view', 'videos.manage'],
        'reviews' => ['reviews.view', 'reviews.manage'],
        'users' => ['users.view', 'users.manage'],
        'roles' => ['roles.view', 'roles.manage'],
        'settings' => ['settings.view', 'settings.manage'],
    ];

    /** @return list<string> */
    public static function all_names(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }
}
