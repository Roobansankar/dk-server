<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Repair Hair Mask',
            'description' => 'Weekly deep-conditioning treatment.',
            'mrp' => 950,
            'selling_price' => 855,
            'gst_inclusive' => true,
        ], $overrides);
    }

    public function test_admin_can_create_a_product_with_an_image(): void
    {
        Storage::fake('public');
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/products', $this->payload([
            'image' => UploadedFile::fake()->image('mask.jpg', 800, 800),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Repair Hair Mask')
            ->assertJsonPath('data.mrp', fn ($v) => (float) $v === 950.0)
            ->assertJsonPath('data.selling_price', fn ($v) => (float) $v === 855.0)
            ->assertJsonPath('data.gst_inclusive', true)
            ->assertJsonPath('data.discount_amount', fn ($v) => (float) $v === 95.0)
            ->assertJsonPath('data.status', true);

        $path = Product::first()->image_path;
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('products/', $path);
        $this->assertStringNotContainsString('mask', $path);
    }

    public function test_product_can_be_created_without_an_image(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/products', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.image_url', null);
    }

    public function test_selling_price_cannot_exceed_mrp(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/products', $this->payload(['mrp' => 500, 'selling_price' => 600]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('selling_price');
    }

    public function test_negative_prices_are_rejected(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/products', $this->payload(['mrp' => -1, 'selling_price' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mrp', 'selling_price']);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/products', $this->payload([
            'image' => UploadedFile::fake()->create('x.php', 8, 'application/x-php'),
        ]))->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_admin_can_update_a_product_and_partial_price_change_is_validated(): void
    {
        $this->actingAsToken($this->superadmin());
        $product = Product::factory()->create(['mrp' => 800, 'selling_price' => 700]);

        $this->putJson("/api/admin/products/{$product->id}", ['selling_price' => 750])
            ->assertOk()
            ->assertJsonPath('data.selling_price', fn ($v) => (float) $v === 750.0);

        // 900 > stored MRP of 800 -> rejected
        $this->putJson("/api/admin/products/{$product->id}", ['selling_price' => 900])
            ->assertStatus(422)
            ->assertJsonValidationErrors('selling_price');
    }

    public function test_deleting_a_product_removes_the_file(): void
    {
        Storage::fake('public');
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/products', $this->payload([
            'image' => UploadedFile::fake()->image('a.jpg'),
        ]))->assertCreated();

        $product = Product::first();
        $path = $product->image_path;

        $this->deleteJson("/api/admin/products/{$product->id}")->assertNoContent();
        Storage::disk('public')->assertMissing($path);
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_public_products_endpoint_returns_active_only_with_expected_shape(): void
    {
        Product::factory()->count(2)->create();
        Product::factory()->inactive()->create();

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'id', 'name', 'description', 'image_url',
                    'mrp', 'selling_price', 'gst_inclusive', 'is_featured', 'created_at', 'updated_at',
                ]],
            ]);
    }

    public function test_a_new_product_is_not_featured_by_default(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/products', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.is_featured', false);

        $this->assertFalse(Product::first()->is_featured);
    }

    public function test_up_to_three_products_can_be_featured_and_a_fourth_unfeatures_the_oldest(): void
    {
        $this->actingAsToken($this->superadmin());
        [$a, $b, $c, $d] = Product::factory()->count(4)->create()->all();

        // Feature A, B, C (a second apart so the order is unambiguous).
        foreach ([$a, $b, $c] as $i => $product) {
            $this->travel($i)->seconds();
            $this->putJson("/api/admin/products/{$product->id}", ['is_featured' => true])
                ->assertOk()
                ->assertJsonPath('data.is_featured', true);
        }
        $this->assertSame(3, Product::where('is_featured', true)->count());

        // Re-saving an already-featured product keeps its place in the order.
        $this->travel(5)->seconds();
        $this->putJson("/api/admin/products/{$a->id}", ['is_featured' => true])->assertOk();
        $this->assertSame(3, Product::where('is_featured', true)->count());

        // Featuring a 4th drops the oldest-featured (A), never more than 3.
        $this->putJson("/api/admin/products/{$d->id}", ['is_featured' => true])
            ->assertOk()
            ->assertJsonPath('data.is_featured', true);
        $this->assertFalse($a->fresh()->is_featured);
        $this->assertNull($a->fresh()->featured_at);
        $this->assertTrue($b->fresh()->is_featured);
        $this->assertTrue($c->fresh()->is_featured);
        $this->assertTrue($d->fresh()->is_featured);
        $this->assertSame(3, Product::where('is_featured', true)->count());

        // Unfeature B -> two left.
        $this->putJson("/api/admin/products/{$b->id}", ['is_featured' => false])
            ->assertOk()
            ->assertJsonPath('data.is_featured', false);
        $this->assertSame(2, Product::where('is_featured', true)->count());
    }

    public function test_creating_a_featured_product_keeps_existing_featured_ones_below_the_limit(): void
    {
        $this->actingAsToken($this->superadmin());
        $existing = Product::factory()->featured()->create();

        $this->postJson('/api/admin/products', $this->payload(['is_featured' => true]))
            ->assertCreated()
            ->assertJsonPath('data.is_featured', true);

        $this->assertTrue($existing->fresh()->is_featured);
        $this->assertSame(2, Product::where('is_featured', true)->count());
    }

    public function test_creating_a_fourth_featured_product_unfeatures_the_oldest(): void
    {
        $this->actingAsToken($this->superadmin());
        $oldest = Product::factory()->featured()->create(['featured_at' => now()->subDays(3)]);
        Product::factory()->featured()->create(['featured_at' => now()->subDays(2)]);
        Product::factory()->featured()->create(['featured_at' => now()->subDay()]);

        $this->postJson('/api/admin/products', $this->payload(['is_featured' => true]))
            ->assertCreated()
            ->assertJsonPath('data.is_featured', true);

        $this->assertFalse($oldest->fresh()->is_featured);
        $this->assertSame(3, Product::where('is_featured', true)->count());
    }

    public function test_updating_a_featured_product_keeps_it_featured(): void
    {
        $this->actingAsToken($this->superadmin());
        $featured = Product::factory()->featured()->create(['mrp' => 800, 'selling_price' => 700]);
        Product::factory()->count(2)->create();

        // An unrelated field change must not touch the featured flag.
        $this->putJson("/api/admin/products/{$featured->id}", ['selling_price' => 650])
            ->assertOk()
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.selling_price', fn ($v) => (float) $v === 650.0);

        $this->assertTrue($featured->fresh()->is_featured);
        $this->assertSame(1, Product::where('is_featured', true)->count());
    }

    public function test_the_public_endpoint_exposes_the_featured_flag(): void
    {
        $featured = Product::factory()->featured()->create();
        Product::factory()->create();

        $data = $this->getJson('/api/products')->assertOk()->json('data');

        $featuredRows = array_filter($data, fn ($p) => $p['is_featured'] === true);
        $this->assertCount(1, $featuredRows);
        $this->assertSame($featured->id, array_values($featuredRows)[0]['id']);
    }

    public function test_products_management_requires_the_right_permission(): void
    {
        $this->actingAsToken($this->userWith(['products.view']));

        $this->getJson('/api/admin/products')->assertOk();
        $this->postJson('/api/admin/products', $this->payload())->assertForbidden();
    }

    public function test_products_admin_list_rejects_users_without_view(): void
    {
        $this->actingAsToken($this->userWith(['services.view']));

        $this->getJson('/api/admin/products')->assertForbidden();
    }

    public function test_stock_available_is_recorded_through_stock_movements(): void
    {
        $this->actingAsToken($this->superadmin());

        $id = $this->postJson('/api/admin/products', $this->payload(['stock_quantity' => 10, 'tax_percent' => 5]))
            ->assertCreated()
            ->assertJsonPath('data.stock_quantity', 10)
            ->assertJsonPath('data.tax_percent', fn ($v) => (float) $v === 5.0)
            ->json('data.id');

        $this->putJson("/api/admin/products/{$id}", ['stock_quantity' => 4])
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 4);

        $this->assertDatabaseHas('product_stock_movements', ['product_id' => $id, 'type' => 'restock', 'quantity' => 10]);
        $this->assertDatabaseHas('product_stock_movements', ['product_id' => $id, 'type' => 'adjustment', 'quantity' => -6]);
    }

    public function test_admin_list_reports_items_sold_from_sale_movements(): void
    {
        $this->actingAsToken($this->superadmin());
        $product = Product::create($this->payload(['slug' => 'repair-hair-mask']));

        $product->stockMovements()->create(['type' => 'restock', 'quantity' => 10, 'balance_after' => 10]);
        $product->stockMovements()->create(['type' => 'sale', 'quantity' => -3, 'balance_after' => 7]);
        $product->stockMovements()->create(['type' => 'sale', 'quantity' => -2, 'balance_after' => 5]);

        $this->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonPath('data.0.items_sold', 5);
    }
}
