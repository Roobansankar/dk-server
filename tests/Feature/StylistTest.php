<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Stylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class StylistTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_public_endpoint_returns_only_active_stylists_in_order(): void
    {
        Stylist::factory()->create(['name' => 'Bea', 'status' => true, 'sort_order' => 1]);
        Stylist::factory()->create(['name' => 'Ada', 'status' => true, 'sort_order' => 0]);
        Stylist::factory()->inactive()->create(['name' => 'Hidden']);

        $this->getJson('/api/stylists')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ada')
            ->assertJsonPath('data.1.name', 'Bea');
    }

    public function test_superadmin_can_create_and_update_a_stylist(): void
    {
        $this->actingAsToken($this->superadmin());

        $created = $this->postJson('/api/admin/stylists', [
            'name' => 'Priya Nair',
            'bio' => 'Colour specialist',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'priya-nair')
            ->assertJsonPath('data.status', true)
            ->json('data.id');

        $this->patchJson("/api/admin/stylists/{$created}", ['status' => false])
            ->assertOk()
            ->assertJsonPath('data.status', false);
    }

    public function test_stylist_management_requires_the_manage_permission(): void
    {
        $this->actingAsToken($this->userWith(['stylists.view']));

        $this->postJson('/api/admin/stylists', ['name' => 'Nope'])->assertForbidden();

        $stylist = Stylist::factory()->create();
        $this->getJson('/api/admin/stylists')->assertOk();
        $this->deleteJson("/api/admin/stylists/{$stylist->id}")->assertForbidden();
    }

    public function test_stylists_can_be_reordered(): void
    {
        $this->actingAsToken($this->superadmin());
        $a = Stylist::factory()->create(['sort_order' => 0]);
        $b = Stylist::factory()->create(['sort_order' => 1]);

        $this->postJson('/api/admin/stylists/reorder', ['ids' => [$b->id, $a->id]])->assertOk();

        $this->assertEquals(0, $b->fresh()->sort_order);
        $this->assertEquals(1, $a->fresh()->sort_order);
    }

    public function test_stylist_with_appointments_cannot_be_deleted(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        Appointment::factory()->forStylist($stylist)->create();

        $this->deleteJson("/api/admin/stylists/{$stylist->id}")->assertStatus(422);
        $this->assertDatabaseHas('stylists', ['id' => $stylist->id, 'deleted_at' => null]);
    }
}
