<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Production-safe: structure only, no fake salon content.
        $this->call([
            RolePermissionSeeder::class,
            AdminUserSeeder::class,
            SiteSettingSeeder::class,
        ]);

        // Development sample data.
        if (! app()->isProduction()) {
            $this->call(DevSeeder::class);
        }
    }
}
