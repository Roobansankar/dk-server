<?php

namespace Tests\Feature;

use App\Models\CatalogueImage;
use App\Models\Combo;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

/**
 * Up to four photos per product and per combo: the first is the cover, the
 * order is the order the detail page shows, and nothing beyond four is accepted.
 */
class CatalogueImagesTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->actingAsToken($this->superadmin());
    }

    private function photo(string $name = 'photo'): UploadedFile
    {
        return UploadedFile::fake()->image("{$name}.jpg", 600, 600);
    }

    /** @return array<int, UploadedFile> */
    private function photos(int $count): array
    {
        return array_map(fn (int $i) => $this->photo("p{$i}"), range(1, $count));
    }

    private function productPayload(array $overrides = []): array
    {
        return array_merge(['name' => 'Repair Hair Mask', 'mrp' => 950, 'selling_price' => 855], $overrides);
    }

    private function comboPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Care Set',
            'items' => [['product_id' => Product::factory()->create()->id, 'price' => 500]],
        ], $overrides);
    }

    /** A product that already has $count photos (created through the API, like the admin does). */
    private function productWithPhotos(int $count): Product
    {
        $id = $this->postJson('/api/admin/products', $this->productPayload(['images' => $this->photos($count)]))
            ->assertCreated()->json('data.id');

        return Product::findOrFail($id);
    }

    private function comboWithPhotos(int $count): Combo
    {
        $id = $this->postJson('/api/admin/combos', $this->comboPayload(['images' => $this->photos($count)]))
            ->assertCreated()->json('data.id');

        return Combo::findOrFail($id);
    }

    /** @return array<int, string> stored paths, in display order */
    private function paths(Product|Combo $owner): array
    {
        return $owner->images()->pluck('path')->all();
    }

    // --- Products: adding photos -------------------------------------------------

    public function test_a_product_can_be_created_with_four_photos_and_the_first_is_the_cover(): void
    {
        $response = $this->postJson('/api/admin/products', $this->productPayload(['images' => $this->photos(4)]))
            ->assertCreated()
            ->assertJsonCount(4, 'data.images');

        $product = Product::findOrFail($response->json('data.id'));
        $paths = $this->paths($product);

        $this->assertCount(4, $paths);
        foreach ($paths as $path) {
            $this->assertStringStartsWith('products/', $path);
            Storage::disk('public')->assertExists($path);
        }

        $this->assertSame($paths[0], $product->image_path, 'cover = first photo');
        $this->assertSame($response->json('data.images.0.url'), $response->json('data.image_url'));
        $this->assertSame([0, 1, 2, 3], $product->images()->pluck('sort_order')->all());
    }

    public function test_a_fifth_photo_is_refused_and_nothing_is_created(): void
    {
        $this->postJson('/api/admin/products', $this->productPayload(['images' => $this->photos(5)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');

        $this->assertSame(0, Product::count());
        $this->assertSame(0, CatalogueImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->postJson('/api/admin/products', $this->productPayload([
            'images' => [$this->photo(), UploadedFile::fake()->create('x.php', 8, 'application/x-php')],
        ]))->assertStatus(422)->assertJsonValidationErrors('images.1');

        $this->assertSame(0, Product::count());
    }

    public function test_photos_are_added_after_the_existing_ones(): void
    {
        $product = $this->productWithPhotos(2);
        $before = $this->paths($product);

        $this->putJson("/api/admin/products/{$product->id}", ['images' => $this->photos(2)])
            ->assertOk()
            ->assertJsonCount(4, 'data.images');

        $after = $this->paths($product->fresh());
        $this->assertSame($before, array_slice($after, 0, 2), 'existing photos keep their place');
        $this->assertCount(4, $after);
    }

    public function test_going_past_four_photos_is_refused_and_changes_nothing(): void
    {
        $product = $this->productWithPhotos(2);
        $before = $this->paths($product);

        $this->putJson("/api/admin/products/{$product->id}", ['name' => 'Renamed', 'images' => $this->photos(3)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');

        $this->assertSame($before, $this->paths($product->fresh()));
        $this->assertSame('Repair Hair Mask', $product->fresh()->name, 'the rest of the edit is not applied either');
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    // --- Products: order, cover, removal -----------------------------------------

    public function test_photos_can_be_reordered_and_removed_with_image_order(): void
    {
        $product = $this->productWithPhotos(3);
        [$a, $b, $c] = $product->images()->get()->all();

        $this->putJson("/api/admin/products/{$product->id}", ['image_order' => ["e:{$c->id}", "e:{$a->id}"]])
            ->assertOk()
            ->assertJsonPath('data.images.0.id', $c->id)
            ->assertJsonPath('data.images.1.id', $a->id)
            ->assertJsonCount(2, 'data.images');

        $product->refresh();
        $this->assertSame([$c->path, $a->path], $this->paths($product));
        $this->assertSame($c->path, $product->image_path, 'the new first photo is the cover');

        // the removed photo is gone from the database AND from disk; the kept ones stay
        $this->assertDatabaseMissing('catalogue_images', ['id' => $b->id]);
        Storage::disk('public')->assertMissing($b->path);
        Storage::disk('public')->assertExists($a->path);
        Storage::disk('public')->assertExists($c->path);
    }

    public function test_a_new_photo_can_be_placed_first_and_becomes_the_cover(): void
    {
        $product = $this->productWithPhotos(2);
        [$a, $b] = $product->images()->get()->all();

        $this->putJson("/api/admin/products/{$product->id}", [
            'images' => [$this->photo('fresh')],
            'image_order' => ['n:0', "e:{$a->id}", "e:{$b->id}"],
        ])->assertOk()->assertJsonCount(3, 'data.images');

        $paths = $this->paths($product->fresh());

        $this->assertNotContains($paths[0], [$a->path, $b->path]);
        $this->assertSame([$a->path, $b->path], array_slice($paths, 1));
        $this->assertSame($paths[0], $product->fresh()->image_path);
    }

    public function test_the_photos_of_another_product_cannot_be_used(): void
    {
        $product = $this->productWithPhotos(1);
        $other = $this->postJson('/api/admin/products', $this->productPayload(['name' => 'Other', 'images' => $this->photos(1)]))
            ->assertCreated()->json('data.images.0.id');

        $this->putJson("/api/admin/products/{$product->id}", ['image_order' => ["e:{$other}"]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image_order.0');

        $this->assertDatabaseHas('catalogue_images', ['id' => $other]);
    }

    public function test_every_uploaded_file_must_be_placed_in_the_order(): void
    {
        $product = $this->productWithPhotos(1);
        $id = $product->images()->value('id');

        $this->putJson("/api/admin/products/{$product->id}", [
            'images' => $this->photos(2),
            'image_order' => ["e:{$id}", 'n:0'], // the second file is never placed
        ])->assertStatus(422)->assertJsonValidationErrors('images');

        $this->putJson("/api/admin/products/{$product->id}", [
            'images' => $this->photos(1),
            'image_order' => ["e:{$id}", 'n:5'], // there is no fifth-index file
        ])->assertStatus(422)->assertJsonValidationErrors('image_order.1');
    }

    public function test_malformed_order_entries_are_refused(): void
    {
        $product = $this->productWithPhotos(1);

        $this->putJson("/api/admin/products/{$product->id}", ['image_order' => ['x:1']])
            ->assertStatus(422)->assertJsonValidationErrors('image_order.0');

        $id = $product->images()->value('id');
        $this->putJson("/api/admin/products/{$product->id}", ['image_order' => ["e:{$id}", "e:{$id}"]])
            ->assertStatus(422)->assertJsonValidationErrors('image_order.0');
    }

    public function test_remove_image_clears_every_photo_and_the_cover(): void
    {
        $product = $this->productWithPhotos(3);
        $paths = $this->paths($product);

        $this->putJson("/api/admin/products/{$product->id}", ['remove_image' => true])
            ->assertOk()
            ->assertJsonCount(0, 'data.images')
            ->assertJsonPath('data.image_url', null);

        $this->assertNull($product->fresh()->image_path);
        $this->assertSame(0, $product->images()->count());
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_the_single_image_field_still_replaces_the_photo_like_before(): void
    {
        $product = $this->productWithPhotos(3);
        $old = $this->paths($product);

        $this->putJson("/api/admin/products/{$product->id}", ['image' => $this->photo('replacement')])
            ->assertOk()
            ->assertJsonCount(1, 'data.images');

        $now = $this->paths($product->fresh());
        $this->assertCount(1, $now);
        $this->assertNotContains($now[0], $old);
        $this->assertSame($now[0], $product->fresh()->image_path);
        foreach ($old as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_editing_other_fields_leaves_the_photos_alone(): void
    {
        $product = $this->productWithPhotos(3);
        $before = $this->paths($product);

        $this->putJson("/api/admin/products/{$product->id}", ['selling_price' => 800, 'description' => 'New text'])
            ->assertOk()
            ->assertJsonCount(3, 'data.images');

        $this->assertSame($before, $this->paths($product->fresh()));
        $this->assertSame($before[0], $product->fresh()->image_path);
    }

    public function test_deleting_a_product_removes_all_its_photos(): void
    {
        $product = $this->productWithPhotos(4);
        $paths = $this->paths($product);

        $this->deleteJson("/api/admin/products/{$product->id}")->assertNoContent();

        $this->assertSame(0, CatalogueImage::count());
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_photos_of_different_items_do_not_mix(): void
    {
        $product = $this->productWithPhotos(2);
        $combo = $this->comboWithPhotos(3);

        $this->assertSame(2, $product->images()->count());
        $this->assertSame(3, $combo->images()->count());

        $this->deleteJson("/api/admin/products/{$product->id}")->assertNoContent();

        $this->assertSame(3, $combo->images()->count());
        $this->assertSame(['combo'], CatalogueImage::query()->distinct()->pluck('imageable_type')->all());
    }

    // --- What the admin and the storefront receive -------------------------------

    public function test_the_admin_list_and_detail_carry_the_photos(): void
    {
        $product = $this->productWithPhotos(3);

        $this->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonCount(3, 'data.0.images');

        $this->getJson("/api/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data.images')
            ->assertJsonStructure(['data' => ['images' => [['id', 'url']]]]);
    }

    public function test_the_public_shelf_exposes_the_photos_in_order_for_active_products_only(): void
    {
        $product = $this->productWithPhotos(3);
        [$a, $b, $c] = $product->images()->get()->all();
        $this->putJson("/api/admin/products/{$product->id}", ['image_order' => ["e:{$b->id}", "e:{$c->id}", "e:{$a->id}"]])->assertOk();

        $hidden = $this->productWithPhotos(1);
        $hidden->update(['status' => false]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonCount(3, 'data.0.images')
            ->assertJsonPath('data.0.images.0.id', $b->id)
            ->assertJsonPath('data.0.images.2.id', $a->id);
    }

    public function test_a_product_without_photos_has_an_empty_list_and_no_cover(): void
    {
        $id = $this->postJson('/api/admin/products', $this->productPayload())->assertCreated()->json('data.id');

        $this->getJson("/api/admin/products/{$id}")
            ->assertOk()
            ->assertJsonPath('data.image_url', null)
            ->assertJsonCount(0, 'data.images');
    }

    // --- Combos ---------------------------------------------------------------------

    public function test_a_combo_can_have_four_photos_and_not_five(): void
    {
        $response = $this->postJson('/api/admin/combos', $this->comboPayload(['images' => $this->photos(4)]))
            ->assertCreated()
            ->assertJsonCount(4, 'data.images');

        $combo = Combo::findOrFail($response->json('data.id'));
        $this->assertSame($this->paths($combo)[0], $combo->image_path);
        foreach ($this->paths($combo) as $path) {
            $this->assertStringStartsWith('combos/', $path);
        }

        $this->postJson('/api/admin/combos', $this->comboPayload(['name' => 'Too many', 'images' => $this->photos(5)]))
            ->assertStatus(422)->assertJsonValidationErrors('images');
    }

    public function test_combo_photos_can_be_reordered_added_and_removed(): void
    {
        $combo = $this->comboWithPhotos(2);
        [$a, $b] = $combo->images()->get()->all();

        $this->putJson("/api/admin/combos/{$combo->id}", [
            'images' => [$this->photo('third')],
            'image_order' => ["e:{$b->id}", 'n:0'], // drops $a, keeps $b first, adds the new one
        ])->assertOk()->assertJsonCount(2, 'data.images')->assertJsonPath('data.images.0.id', $b->id);

        $this->assertSame($b->path, $combo->fresh()->image_path);
        $this->assertDatabaseMissing('catalogue_images', ['id' => $a->id]);
        Storage::disk('public')->assertMissing($a->path);
    }

    public function test_editing_a_combo_without_touching_photos_keeps_them(): void
    {
        $combo = $this->comboWithPhotos(2);
        $before = $this->paths($combo);

        $this->putJson("/api/admin/combos/{$combo->id}", ['name' => 'Renamed set'])
            ->assertOk()->assertJsonCount(2, 'data.images');

        $this->assertSame($before, $this->paths($combo->fresh()));
    }

    public function test_the_public_combo_list_exposes_the_photos_and_deleting_a_combo_removes_them(): void
    {
        $combo = $this->comboWithPhotos(3);
        $paths = $this->paths($combo);

        $this->getJson('/api/combos')
            ->assertOk()
            ->assertJsonPath('data.0.id', $combo->id)
            ->assertJsonCount(3, 'data.0.images');

        $this->deleteJson("/api/admin/combos/{$combo->id}")->assertNoContent();

        $this->assertSame(0, CatalogueImage::count());
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_only_people_who_may_manage_products_can_change_photos(): void
    {
        $product = $this->productWithPhotos(1);
        $this->actingAsToken($this->userWith(['products.view']));

        $this->putJson("/api/admin/products/{$product->id}", ['images' => $this->photos(1)])->assertForbidden();
        $this->assertSame(1, $product->images()->count());
    }
}
