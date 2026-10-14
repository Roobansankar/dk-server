<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Additive, data only: adds the "Pay at Store limit" setting row so the admin
 * Settings page can show and save it (SiteSettingController only updates keys
 * that already exist). Created EMPTY — Pay at Store stays off until an admin
 * enters a limit; no threshold is assumed. Existing rows are never touched.
 */
return new class extends Migration
{
    private const ROW = ['key' => 'pay_at_store_max_amount', 'value' => null, 'type' => 'currency', 'group' => 'payments'];

    public function up(): void
    {
        if (! DB::table('site_settings')->where('key', self::ROW['key'])->exists()) {
            DB::table('site_settings')->insert(self::ROW + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget(SiteSetting::CACHE_KEY);
    }

    public function down(): void
    {
        DB::table('site_settings')->where('key', self::ROW['key'])->delete();

        Cache::forget(SiteSetting::CACHE_KEY);
    }
};
