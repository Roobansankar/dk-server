<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class ServiceCategoryTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_superadmin_can_create_a_category(): void
    {
        $this->actingAsToken($this->superadmin());

        $response = $this->postJson('/api/admin/service-categories', [
            'gender' => 'female',
            'name' => 'Hair',
            'description' => 'Cuts and styling',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'hair')
            ->assertJsonPath('data.gender', 'female');

        $this->assertDatabaseHas('service_categories', ['name' => 'Hair', 'gender' => 'female']);
    }

    public function test_same_slug_is_allowed_across_genders_but_not_within(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/service-categories', ['gender' => 'female', 'name' => 'Hair'])->assertCreated();
        $this->postJson('/api/admin/service-categories', ['gender' => 'male', 'name' => 'Hair'])
            ->assertCreated()->assertJsonPath('data.slug', 'hair');
        $this->postJson('/api/admin/service-categories', ['gender' => 'female', 'name' => 'Hair'])
            ->assertCreated()->assertJsonPath('data.slug', 'hair-2');
    }

    public function test_male_and_female_lists_are_separated(): void
    {
        $this->actingAsToken($this->superadmin());
        ServiceCategory::factory()->male()->count(2)->create();
        ServiceCategory::factory()->female()->count(3)->create();

        $this->getJson('/api/admin/service-categories?gender=male')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/service-categories?gender=female')
            ->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_category_with_services_cannot_be_deleted(): void
    {
        $this->actingAsToken($this->superadmin());
        $category = ServiceCategory::factory()->create();
        Service::factory()->forCategory($category)->create();

        $this->deleteJson("/api/admin/service-categories/{$category->id}")->assertStatus(422);
        $this->assertDatabaseHas('service_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_empty_category_can_be_deleted(): void
    {
        $this->actingAsToken($this->superadmin());
        $category = ServiceCategory::factory()->create();

        $this->deleteJson("/api/admin/service-categories/{$category->id}")->assertNoContent();
        $this->assertSoftDeleted($category);
    }

    public function test_category_type_can_be_set_and_updated(): void
    {
        $this->actingAsToken($this->superadmin());

        $id = $this->postJson('/api/admin/service-categories', [
            'gender' => 'female', 'name' => 'Hair', 'category_type' => 'hair',
        ])->assertCreated()
            ->assertJsonPath('data.category_type', 'hair')
            ->json('data.id');

        $this->patchJson("/api/admin/service-categories/{$id}", ['category_type' => 'skin'])
            ->assertOk()->assertJsonPath('data.category_type', 'skin');

        $this->patchJson("/api/admin/service-categories/{$id}", ['category_type' => 'nonsense'])
            ->assertStatus(422);
    }

    public function test_category_type_is_exposed_on_the_public_catalogue(): void
    {
        ServiceCategory::factory()->type('skin')->create(['status' => true]);

        $this->getJson('/api/service-categories')
            ->assertOk()
            ->assertJsonPath('data.0.category_type', 'skin');
    }

    public function test_categories_can_be_reordered(): void
    {
        $this->actingAsToken($this->superadmin());
        $a = ServiceCategory::factory()->create(['sort_order' => 0]);
        $b = ServiceCategory::factory()->create(['sort_order' => 1]);

        $this->postJson('/api/admin/service-categories/reorder', ['ids' => [$b->id, $a->id]])->assertOk();

        $this->assertEquals(0, $b->fresh()->sort_order);
        $this->assertEquals(1, $a->fresh()->sort_order);
    }
}
