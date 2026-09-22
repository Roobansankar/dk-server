<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * DEVELOPMENT ONLY. Seeds the public service catalogue (categories + services)
 * so the live `/api/service-categories` and `/api/services` endpoints — and the
 * homepage booking form — have real, numeric-ID records to submit against,
 * instead of falling back to the frontend's static sample menu.
 *
 * The category/service names, gender split and durations/prices mirror the
 * frontend fallback catalogue as closely as the backend schema allows —
 * `frontend/src/data/services.js` — so the live menu and the sample menu look
 * the same to a visitor. Prices are indicative placeholders (see that file's
 * own "TEMPORARY PLACEHOLDER" notice); replace with real studio pricing when
 * available.
 *
 * `service_categories` has no "unisex" gender column (only male|female — see
 * ServiceCategory::GENDERS). A fallback service tagged for every gender is
 * therefore seeded twice — once under the male category, once under the
 * female category of the same slug — exactly as the existing catalogue in
 * DevSeeder already does. The frontend merges both by slug into one category
 * with a combined service list, so "Prefer not to say" still shows it.
 *
 * Idempotent: every category and service is created with `firstOrCreate`
 * keyed to the table's own unique constraint (`[gender, slug]` and
 * `[service_category_id, slug]`), so running this seeder again updates
 * nothing and creates no duplicates. Additive only — nothing is deleted or
 * truncated.
 */
class ServiceCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('ServiceCatalogueSeeder must not run in production.');

            return;
        }

        // Genders a service is tagged for in the frontend fallback, expressed
        // as which backend (gender-specific) category row(s) it belongs to.
        $BOTH = ['male', 'female'];

        $categories = [
            [
                'slug' => 'haircuts-and-styling',
                'name' => 'Haircuts and Styling',
                'description' => 'From classic cuts to the latest trends.',
                'type' => ServiceCategory::TYPE_HAIR,
                'services' => [
                    ['name' => "Men's Haircut", 'genders' => ['male'], 'duration' => 40, 'price' => 600, 'advance' => 0],
                    ['name' => "Women's Haircut", 'genders' => ['female'], 'duration' => 60, 'price' => 1200, 'advance' => 0],
                    ['name' => 'Hair Wash & Blow-Dry', 'genders' => $BOTH, 'duration' => 40, 'price' => 700, 'advance' => 0],
                    ['name' => 'Beard Trim & Shape', 'genders' => ['male'], 'duration' => 20, 'price' => 350, 'advance' => 0],
                ],
            ],
            [
                'slug' => 'color-and-highlights',
                'name' => 'Color and Highlights',
                'description' => 'Colour work using high-quality products to keep hair healthy and radiant.',
                'type' => ServiceCategory::TYPE_HAIR,
                'services' => [
                    ['name' => 'Root Touch-Up', 'genders' => $BOTH, 'duration' => 60, 'price' => 1500, 'advance' => 20],
                    ['name' => 'Global Colour', 'genders' => $BOTH, 'duration' => 120, 'price' => 3200, 'advance' => 20],
                    ['name' => 'Highlights — Partial', 'genders' => $BOTH, 'duration' => 150, 'price' => 4500, 'advance' => 25],
                    ['name' => 'Balayage', 'genders' => $BOTH, 'duration' => 180, 'price' => 6500, 'advance' => 25],
                ],
            ],
            [
                'slug' => 'hair-treatment',
                'name' => 'Hair Treatment',
                'description' => 'Treatments that provide intense moisture and nutrients to restore softness and manageability.',
                'type' => ServiceCategory::TYPE_HAIR,
                'services' => [
                    ['name' => 'Hair Spa', 'genders' => $BOTH, 'duration' => 45, 'price' => 1400, 'advance' => 0],
                    ['name' => 'Keratin Smoothening', 'genders' => $BOTH, 'duration' => 150, 'price' => 5500, 'advance' => 25],
                    ['name' => 'Anti-Hairfall Treatment', 'genders' => $BOTH, 'duration' => 60, 'price' => 2200, 'advance' => 20],
                ],
            ],
            [
                'slug' => 'skin-care-and-makeup',
                'name' => 'Skin Care and Makeup',
                'description' => 'Facials, makeup application, and other beauty treatments.',
                'type' => ServiceCategory::TYPE_SKIN,
                'services' => [
                    ['name' => 'Express Facial', 'genders' => $BOTH, 'duration' => 45, 'price' => 1600, 'advance' => 0],
                    ['name' => 'Brightening Facial', 'genders' => $BOTH, 'duration' => 75, 'price' => 3200, 'advance' => 20],
                    ['name' => 'Clean-Up', 'genders' => $BOTH, 'duration' => 30, 'price' => 900, 'advance' => 0],
                    // Fallback tags this ['women', 'unisex'] only — no male copy.
                    ['name' => 'Occasion Makeup', 'genders' => ['female'], 'duration' => 90, 'price' => 3800, 'advance' => 20],
                ],
            ],
            [
                'slug' => 'massage-and-relaxation',
                'name' => 'Massage and Relaxation',
                'description' => 'Massage therapies designed to reduce stress and promote well-being.',
                // Model has no "massage" type; DevSeeder's existing convention
                // maps massage into the closest of the two allowed types.
                'type' => ServiceCategory::TYPE_SKIN,
                'services' => [
                    ['name' => 'Head Massage', 'genders' => $BOTH, 'duration' => 30, 'price' => 600, 'advance' => 0],
                    ['name' => 'Head & Shoulder Massage', 'genders' => $BOTH, 'duration' => 45, 'price' => 950, 'advance' => 0],
                    ['name' => 'Back Massage', 'genders' => $BOTH, 'duration' => 45, 'price' => 1500, 'advance' => 0],
                ],
            ],
        ];

        $categoryOrder = ['male' => 0, 'female' => 0];

        foreach ($categories as $categoryDef) {
            // One category row per gender (schema constraint), same slug —
            // the public API/frontend merge these back into one menu entry.
            $categoryByGender = [];
            foreach (['male', 'female'] as $gender) {
                $categoryByGender[$gender] = ServiceCategory::firstOrCreate(
                    ['gender' => $gender, 'slug' => $categoryDef['slug']],
                    [
                        'name' => $categoryDef['name'],
                        'description' => $categoryDef['description'],
                        'category_type' => $categoryDef['type'],
                        'status' => true,
                        'sort_order' => $categoryOrder[$gender]++,
                    ],
                );
            }

            $serviceOrder = ['male' => 0, 'female' => 0];
            foreach ($categoryDef['services'] as $serviceDef) {
                foreach ($serviceDef['genders'] as $gender) {
                    $category = $categoryByGender[$gender];

                    Service::firstOrCreate(
                        ['service_category_id' => $category->id, 'slug' => Str::slug($serviceDef['name'])],
                        [
                            'name' => $serviceDef['name'],
                            'duration_minutes' => $serviceDef['duration'],
                            'price' => $serviceDef['price'],
                            'advance_percentage' => $serviceDef['advance'],
                            'status' => true,
                            'sort_order' => $serviceOrder[$gender]++,
                        ],
                    );
                }
            }
        }
    }
}
