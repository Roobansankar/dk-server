<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

/**
 * Production-safe. Seeds the settings keys the public site needs. Values are
 * taken ONLY from what the project already documents (src/data/site.js); keys
 * with no known value are created empty for an admin to fill in later — nothing
 * is invented.
 */
class SiteSettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // key, value, type, group
            ['salon_name', 'DK StyleHub', 'string', 'general'],
            ['description', 'A premium unisex beauty and styling studio — hair, colour, skin and massage, for everyone.', 'text', 'general'],
            ['phone', '+91 97904 31212', 'string', 'contact'],
            ['phone_href', 'tel:+919790431212', 'string', 'contact'],
            ['email', null, 'string', 'contact'],
            ['address', null, 'text', 'contact'],
            ['business_hours', null, 'text', 'contact'],
            // Manual opening hours — shown on the public Contact page and used
            // to derive the booking picker's slot range. Defaults mirror the
            // hours the picker shipped with.
            ['shop_opens_at', '10:00', 'time', 'shop_hours'],
            ['shop_closes_at', '19:30', 'time', 'shop_hours'],
            ['instagram_url', 'https://www.instagram.com/the_dk__stylehub', 'string', 'social'],
            ['whatsapp_url', 'https://wa.me/message/B32HQTKZGU3HF1', 'string', 'social'],
            ['facebook_url', null, 'string', 'social'],
            ['logo_path', null, 'string', 'branding'],
            ['favicon_path', null, 'string', 'branding'],
        ];

        foreach ($defaults as [$key, $value, $type, $group]) {
            SiteSetting::firstOrCreate(
                ['key' => $key],
                ['value' => $value, 'type' => $type, 'group' => $group],
            );
        }
    }
}
