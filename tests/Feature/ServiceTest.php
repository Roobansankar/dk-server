<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_admin_can_create_a_service_under_a_category(): void
    {
        $this->actingAsToken($this->superadmin());
        $category = ServiceCategory::factory()->female()->create();

        $response = $this->postJson('/api/admin/services', [
            'service_category_id' => $category->id,
            'name' => 'Balayage',
            'duration_minutes' => 120,
            'price' => 6500,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'balayage')
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.price', 6500);
    }

    public function test_service_can_be_deactivated_and_soft_deleted(): void
    {
        $this->actingAsToken($this->superadmin());
        $service = Service::factory()->create();

        $this->patchJson("/api/admin/services/{$service->id}", ['status' => false])
            ->assertOk()->assertJsonPath('data.status', false);

        $this->deleteJson("/api/admin/services/{$service->id}")->assertNoContent();
        $this->assertSoftDeleted($service);
    }

    public function test_price_must_be_numeric(): void
    {
        $this->actingAsToken($this->superadmin());
        $category = ServiceCategory::factory()->create();

        $this->postJson('/api/admin/services', [
            'service_category_id' => $category->id,
            'name' => 'Bad',
            'price' => 'free',
        ])->assertStatus(422)->assertJsonValidationErrors('price');
    }

    public function test_advance_percentage_must_be_between_0_and_100(): void
    {
        $this->actingAsToken($this->superadmin());
        $category = ServiceCategory::factory()->create();

        foreach ([-5, 101, 'half'] as $bad) {
            $this->postJson('/api/admin/services', [
                'service_category_id' => $category->id,
                'name' => 'Svc '.$bad,
                'price' => 1000,
                'advance_percentage' => $bad,
            ])->assertStatus(422)->assertJsonValidationErrors('advance_percentage');
        }
    }

    public function test_advance_amount_is_calculated_from_price_and_percentage(): void
    {
        $this->actingAsToken($this->superadmin());
        $category = ServiceCategory::factory()->female()->create();

        $this->postJson('/api/admin/services', [
            'service_category_id' => $category->id,
            'name' => 'Balayage',
            'price' => 2000,
            'advance_percentage' => 25,
        ])->assertCreated()
            ->assertJsonPath('data.advance_percentage', 25)
            ->assertJsonPath('data.advance_amount', 500);

        // update the percentage -> amount recalculates
        $service = Service::firstWhere('name', 'Balayage');
        $this->patchJson("/api/admin/services/{$service->id}", ['advance_percentage' => 50])
            ->assertOk()
            ->assertJsonPath('data.advance_amount', 1000);
    }

    public function test_advance_configuration_flows_to_the_public_services_api(): void
    {
        $category = ServiceCategory::factory()->male()->create();
        Service::factory()->forCategory($category)->create([
            'name' => 'Deluxe Shave',
            'price' => 800,
            'advance_percentage' => 10,
        ]);

        $this->getJson('/api/services?gender=male')
            ->assertOk()
            ->assertJsonPath('data.0.advance_percentage', 10)
            ->assertJsonPath('data.0.advance_amount', 80)
            ->assertJsonPath('data.0.duration_minutes', fn ($v) => is_int($v))
            ->assertJsonPath('data.0.price', 800);
    }
}
