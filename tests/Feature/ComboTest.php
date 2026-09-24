<?php

namespace Tests\Feature;

use App\Models\Combo;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class ComboTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_admin_can_create_a_combo_with_included_products_and_combo_prices(): void
    {
        $this->actingAsToken($this->superadmin());
        [$p1, $p2, $p3] = Product::factory()->count(3)->create(['selling_price' => 999]);

        $this->postJson('/api/admin/combos', [
            'name' => 'Hair Care Package',
            'items' => [
                ['product_id' => $p1->id, 'price' => 500],
                ['product_id' => $p2->id, 'price' => 600],
                ['product_id' => $p3->id, 'price' => 450],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Hair Care Package')
            ->assertJsonPath('data.slug', 'hair-care-package')
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.product_id', $p1->id)
            ->assertJsonPath('data.items.0.price', fn ($v) => (float) $v === 500.0)
            ->assertJsonPath('data.items.1.price', fn ($v) => (float) $v === 600.0)
            ->assertJsonPath('data.items.2.price', fn ($v) => (float) $v === 450.0);

        // Combo prices are stored independently of the products' own price.
        $combo = Combo::first();
        $this->assertEqualsCanonicalizing(
            ['500.00', '600.00', '450.00'],
            $combo->items->pluck('price')->all(),
        );
    }

    public function test_combo_requires_products_and_a_price_for_each(): void
    {
        $this->actingAsToken($this->superadmin());
        $product = Product::factory()->create();

        $this->postJson('/api/admin/combos', ['name' => 'Empty', 'items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->postJson('/api/admin/combos', ['name' => 'No price', 'items' => [['product_id' => $product->id]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.price');

        $this->postJson('/api/admin/combos', ['name' => 'Missing product', 'items' => [['product_id' => 999999, 'price' => 10]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');

        $this->postJson('/api/admin/combos', ['name' => 'Duplicate', 'items' => [
            ['product_id' => $product->id, 'price' => 10],
            ['product_id' => $product->id, 'price' => 20],
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_admin_can_update_combo_items_and_delete_a_combo(): void
    {
        $this->actingAsToken($this->superadmin());
        [$p1, $p2] = Product::factory()->count(2)->create();
        $combo = $this->makeCombo([$p1->id => 100, $p2->id => 200]);

        $this->patchJson("/api/admin/combos/{$combo->id}", [
            'items' => [['product_id' => $p2->id, 'price' => 250]],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.price', fn ($v) => (float) $v === 250.0);

        $this->deleteJson("/api/admin/combos/{$combo->id}")->assertNoContent();
        $this->assertSoftDeleted('combos', ['id' => $combo->id]);
    }

    public function test_combo_management_uses_product_permissions(): void
    {
        $this->actingAsToken($this->userWith(['products.view']));

        $this->getJson('/api/admin/combos')->assertOk();
        $this->postJson('/api/admin/combos', ['name' => 'X', 'items' => []])->assertForbidden();
    }

    public function test_public_combo_list_shows_only_active_combos_with_combo_prices(): void
    {
        $p1 = Product::factory()->create(['selling_price' => 999]);
        $this->makeCombo([$p1->id => 500], ['name' => 'Active combo']);
        $this->makeCombo([$p1->id => 500], ['name' => 'Hidden combo', 'status' => false]);

        $this->getJson('/api/combos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active combo')
            ->assertJsonPath('data.0.items.0.price', fn ($v) => (float) $v === 500.0);
    }

    private function makeCombo(array $prices, array $attributes = []): Combo
    {
        $combo = Combo::create(array_merge(['name' => 'Combo', 'slug' => 'combo-'.uniqid()], $attributes));
        foreach (array_keys($prices) as $i => $productId) {
            $combo->items()->create(['product_id' => $productId, 'price' => $prices[$productId], 'sort_order' => $i]);
        }

        return $combo;
    }
}
