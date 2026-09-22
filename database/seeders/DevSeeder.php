<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\GalleryImage;
use App\Models\PricingPlan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Stylist;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * DEVELOPMENT ONLY. Fills the database with believable sample content so the
 * admin panel and API can be exercised. Never run in production.
 */
class DevSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DevSeeder must not run in production.');

            return;
        }

        // A demo admin (non-superadmin) for testing scoped permissions.
        $admin = User::firstOrCreate(
            ['email' => 'admin@dkstylehub.com'],
            ['name' => 'Admin', 'password' => Hash::make('123456'), 'status' => 'active'],
        );
        $admin->syncRoles([Role::ADMIN]);

        if (! User::role(Role::SUPERADMIN)->exists()) {
            $super = User::firstOrCreate(
                ['email' => 'superadmin@dkstylehub.com'],
                ['name' => 'Superadmin', 'password' => Hash::make('123456'), 'status' => 'active'],
            );
            $super->assignRole(Role::SUPERADMIN);
        }

        // A demo customer account (no staff role) for exercising the public
        // account/login flows against a known, stable email. Left password
        // untouched if it already exists — see firstOrCreate below.
        User::firstOrCreate(
            ['email' => 'user@dkstylehub.com'],
            [
                'name' => 'User',
                'password' => Hash::make(Str::random(40)),
                'status' => 'active',
                'type' => User::TYPE_CUSTOMER,
            ],
        );

        // Catalogue — mirrors the frontend fallback data
        // (frontend/src/data/services.js) as closely as the schema allows.
        $this->call(ServiceCatalogueSeeder::class);

        PricingPlan::factory()->count(4)->create();
        GalleryImage::factory()->count(8)->create();

        // Retail products for the public /products shelf.
        $products = [
            ['Gentle Cleansing Shampoo', 'A low-lather daily wash that respects colour and scalp.', 480, 480, true],
            ['Repair Hair Mask', 'A weekly treatment for lengths that feel dry or over-worked.', 950, 855, true],
            ['Sea Salt Spray', 'Effortless body and separation on air-dried hair.', 620, 620, true],
            ['Matte Texture Clay', 'Pliable hold with a dry, natural finish.', 700, 630, true],
            ['Daily Facial Cleanser', 'A soft gel wash that leaves skin comfortable, never tight.', 540, 540, false],
            ['Conditioning Beard Oil', 'Softens coarse growth and tidies the skin beneath.', 450, 405, true],
        ];
        foreach ($products as $i => [$name, $desc, $mrp, $selling, $gst]) {
            Product::firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'description' => $desc,
                    'mrp' => $mrp,
                    'selling_price' => $selling,
                    'gst_inclusive' => $gst,
                    'status' => true,
                    'sort_order' => $i,
                ],
            );
        }

        // Stylists — the public booking form + "Meet the team" section read these.
        $stylistNames = ['Devi K', 'Karan M', 'Aarti S', 'Rohan P', 'Meera J', 'Sam T'];
        $stylists = collect($stylistNames)->map(fn ($name, $i) => Stylist::firstOrCreate(
            ['slug' => Str::slug($name)],
            [
                'name' => $name,
                'bio' => 'Senior stylist at DK StyleHub.',
                'status' => true,
                'sort_order' => $i,
            ],
        ));

        // A few offline (staff-created) appointments for the Offline History view.
        Service::with('category')->inRandomOrder()->take(4)->get()->each(function (Service $service) use ($stylists) {
            Appointment::factory()
                ->count(random_int(1, 2))
                ->offline()
                ->forService($service)
                ->forStylist($stylists->random())
                ->create();

            Appointment::factory()
                ->offline()
                ->forService($service)
                ->forStylist($stylists->random())
                ->completed()
                ->create();
        });

        // Appointments spread across statuses and dates for dashboard analytics.
        Service::with('category')->get()->each(function (Service $service) {
            Appointment::factory()
                ->count(random_int(1, 4))
                ->forService($service)
                ->create();

            // Some completed + paid ones so Payments/History have data.
            Appointment::factory()
                ->count(random_int(1, 3))
                ->forService($service)
                ->completed()
                ->create();
        });

        Appointment::factory()->count(12)->pending()->create();

        // Spread request timestamps across the last 30 days so the dashboard
        // trend chart has something believable to show in development.
        Appointment::query()->get()->each(function (Appointment $appointment) {
            $created = now()->subDays(random_int(0, 29))->subMinutes(random_int(0, 1439));
            $appointment->forceFill([
                'created_at' => $created,
                'updated_at' => $created,
            ])->saveQuietly();
        });
    }
}
