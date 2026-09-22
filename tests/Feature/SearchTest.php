<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_query_returns_empty_results_without_erroring(): void
    {
        $this->getJson('/api/search')->assertOk()
            ->assertJson(['services' => [], 'products' => []]);

        $this->getJson('/api/search?q=')->assertOk()
            ->assertJson(['services' => [], 'products' => []]);
    }

    public function test_it_matches_a_product_by_name(): void
    {
        Product::factory()->create(['name' => 'Sea Salt Spray']);
        Product::factory()->create(['name' => 'Matte Clay']);

        $response = $this->getJson('/api/search?q=sea salt')->assertOk();

        $names = collect($response->json('products'))->pluck('name');
        $this->assertTrue($names->contains('Sea Salt Spray'));
        $this->assertFalse($names->contains('Matte Clay'));
    }

    public function test_it_matches_a_product_by_description(): void
    {
        Product::factory()->create(['name' => 'Unrelated', 'description' => 'A lightweight beard oil for daily grooming.']);

        $response = $this->getJson('/api/search?q=beard oil')->assertOk();

        $this->assertCount(1, $response->json('products'));
    }

    public function test_a_newly_created_active_product_is_immediately_searchable(): void
    {
        // Simulates: admin uploads a new product -> saved in MySQL -> public
        // API exposes it -> global search finds it, with no frontend change.
        $product = Product::factory()->create(['name' => 'Brand New Hydrating Toner']);

        $this->getJson('/api/products')->assertOk()
            ->assertJsonFragment(['name' => 'Brand New Hydrating Toner']);

        $this->getJson('/api/search?q=hydrating toner')->assertOk()
            ->assertJsonFragment(['id' => $product->id, 'name' => 'Brand New Hydrating Toner']);
    }

    public function test_an_inactive_product_is_excluded_from_search_just_like_the_public_list(): void
    {
        Product::factory()->inactive()->create(['name' => 'Discontinued Shampoo']);

        $this->getJson('/api/products')->assertOk()->assertJsonMissing(['name' => 'Discontinued Shampoo']);
        $this->getJson('/api/search?q=discontinued')->assertOk()
            ->assertJson(['products' => []]);
    }

    public function test_a_soft_deleted_product_is_excluded_from_search(): void
    {
        $product = Product::factory()->create(['name' => 'Soon Deleted Serum']);
        $product->delete();

        $this->getJson('/api/search?q=deleted serum')->assertOk()
            ->assertJson(['products' => []]);
    }

    public function test_it_matches_a_service_by_name_and_by_category_name(): void
    {
        $category = ServiceCategory::factory()->create(['name' => 'Bridal Makeup']);
        Service::factory()->forCategory($category)->create(['name' => 'Full Bridal Package']);
        $other = Service::factory()->create(['name' => 'Everyday Haircut']);

        $byService = $this->getJson('/api/search?q=full bridal')->assertOk();
        $this->assertCount(1, $byService->json('services'));

        $byCategory = $this->getJson('/api/search?q=bridal makeup')->assertOk();
        $names = collect($byCategory->json('services'))->pluck('name');
        $this->assertTrue($names->contains('Full Bridal Package'));
    }

    public function test_an_inactive_service_or_inactive_category_is_excluded(): void
    {
        $inactiveCategory = ServiceCategory::factory()->inactive()->create(['name' => 'Retired Category XYZ']);
        Service::factory()->forCategory($inactiveCategory)->create(['name' => 'Retired Service XYZ']);

        $activeCategory = ServiceCategory::factory()->create();
        Service::factory()->forCategory($activeCategory)->inactive()->create(['name' => 'Paused Service XYZ']);

        $this->getJson('/api/search?q=XYZ')->assertOk()->assertJson(['services' => []]);
    }

    public function test_special_characters_are_handled_safely_without_a_server_error(): void
    {
        Product::factory()->create(['name' => '100% Natural Oil']);

        foreach (['%', '_', "' OR '1'='1", '"; DROP TABLE products; --', '💇'] as $weird) {
            $this->getJson('/api/search?q='.urlencode($weird))->assertOk();
        }

        // A literal '%' in the query must not act as a wildcard matching everything.
        $response = $this->getJson('/api/search?q='.urlencode('100%'))->assertOk();
        $this->assertLessThanOrEqual(1, count($response->json('products')));
    }

    public function test_query_longer_than_the_limit_is_rejected_with_validation_error(): void
    {
        $this->getJson('/api/search?q='.str_repeat('a', 200))->assertStatus(422);
    }

    /**
     * Two services legitimately sharing a name (a men's and a women's row of
     * the same catalogue entry — real, distinct, separately bookable rows,
     * not a search/API bug) must BOTH be returned, each carrying enough to
     * tell them apart. Confirms the root cause is real distinct backend
     * records, not duplicate API results — and that the fix disambiguates
     * rather than incorrectly dropping one.
     */
    public function test_same_named_services_in_different_gender_categories_both_appear_distinctly(): void
    {
        $menCategory = ServiceCategory::factory()->male()->create(['name' => 'Haircuts and Styling']);
        $womenCategory = ServiceCategory::factory()->female()->create(['name' => 'Haircuts and Styling']);
        $menService = Service::factory()->forCategory($menCategory)->create(['name' => 'Hair Spa']);
        $womenService = Service::factory()->forCategory($womenCategory)->create(['name' => 'Hair Spa']);

        $response = $this->getJson('/api/search?q=hair spa')->assertOk();
        $services = collect($response->json('services'));

        $this->assertCount(2, $services);
        $ids = $services->pluck('id');
        $this->assertTrue($ids->contains($menService->id));
        $this->assertTrue($ids->contains($womenService->id));
        $this->assertEqualsCanonicalizing(
            ['male', 'female'],
            $services->pluck('gender')->all(),
        );
    }

    public function test_clicking_a_product_result_resolves_to_its_existing_detail_page_slug(): void
    {
        $product = Product::factory()->create(['name' => 'Findable Product']);

        $response = $this->getJson('/api/search?q=findable')->assertOk();
        $slug = collect($response->json('products'))->firstWhere('id', $product->id)['slug'];

        $this->assertSame($product->slug, $slug);
        $this->getJson('/api/products')->assertOk()->assertJsonFragment(['slug' => $slug]);
    }
}
