<?php

namespace Tests\Feature;

use App\Models\GalleryImage;
use App\Models\PricingPlan;
use App\Models\Service;
use App\Models\ServiceCategory;
use Database\Seeders\SiteSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_categories_endpoint_only_returns_active(): void
    {
        ServiceCategory::factory()->count(2)->create();
        ServiceCategory::factory()->inactive()->create();

        $this->getJson('/api/service-categories')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_services_endpoint_hides_inactive_service_and_inactive_category(): void
    {
        $active = ServiceCategory::factory()->create();
        Service::factory()->forCategory($active)->count(2)->create();
        Service::factory()->forCategory($active)->inactive()->create();

        $hiddenCategory = ServiceCategory::factory()->inactive()->create();
        Service::factory()->forCategory($hiddenCategory)->create();

        $this->getJson('/api/services')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_services_can_be_filtered_by_gender(): void
    {
        $male = ServiceCategory::factory()->male()->create();
        $female = ServiceCategory::factory()->female()->create();
        Service::factory()->forCategory($male)->count(2)->create();
        Service::factory()->forCategory($female)->count(3)->create();

        $this->getJson('/api/services?gender=male')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_gallery_and_pricing_endpoints_only_return_active(): void
    {
        GalleryImage::factory()->count(2)->create();
        GalleryImage::factory()->inactive()->create();
        PricingPlan::factory()->count(1)->create();
        PricingPlan::factory()->inactive()->create();

        $this->getJson('/api/gallery')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/pricing-plans')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_site_settings_endpoint_returns_seeded_values(): void
    {
        $this->seed(SiteSettingSeeder::class);

        $this->getJson('/api/site-settings')
            ->assertOk()
            ->assertJsonPath('data.salon_name', 'DK StyleHub');
    }

    public function test_inactive_category_show_returns_404(): void
    {
        $category = ServiceCategory::factory()->inactive()->create();

        $this->getJson("/api/service-categories/{$category->id}")->assertNotFound();
    }
}
