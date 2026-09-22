<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Additive: adds the manual "Shop opens at" / "Shop closes at" settings so the
 * admin Settings page and the public Contact page have something to read, and
 * so the booking picker can derive its slot range from a saved value instead
 * of a hard-coded list. Existing rows are never touched; the defaults mirror
 * the hours the picker already shipped with (10:00 – 19:30).
 */
return new class extends Migration
{
    private const DEFAULTS = [
        ['key' => 'shop_opens_at', 'value' => '10:00', 'type' => 'time', 'group' => 'shop_hours'],
        ['key' => 'shop_closes_at', 'value' => '19:30', 'type' => 'time', 'group' => 'shop_hours'],
    ];

    public function up(): void
    {
        foreach (self::DEFAULTS as $row) {
            if (! DB::table('site_settings')->where('key', $row['key'])->exists()) {
                DB::table('site_settings')->insert($row + [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Cache::forget(SiteSetting::CACHE_KEY);
    }

    public function down(): void
    {
        DB::table('site_settings')
            ->whereIn('key', array_column(self::DEFAULTS, 'key'))
            ->delete();

        Cache::forget(SiteSetting::CACHE_KEY);
    }
};
