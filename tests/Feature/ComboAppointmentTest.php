<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Combo;
use App\Models\PricingPlan;
use App\Models\Product;
use App\Models\Stylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesAdmins;
use Tests\Concerns\CreatesBookableStylists;
use Tests\TestCase;

class ComboAppointmentTest extends TestCase
{
    use CreatesAdmins, CreatesBookableStylists, RefreshDatabase;

    private function plan(array $attributes = []): PricingPlan
    {
        return PricingPlan::create(array_merge([
            'name' => 'Bridal Glow Package',
            'slug' => 'bridal-glow-package-'.PricingPlan::count(),
            'price' => 4999,
            'duration_minutes' => 90,
            'features' => ['Facial', 'Hair spa'],
        ], $attributes));
    }

    private function payload(PricingPlan $plan, Stylist $stylist, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Walk-in Guest',
            'phone' => '+91 9876543210',
            'gender' => 'female',
            'pricing_plan_id' => $plan->id,
            'stylist_id' => $stylist->id,
            'appointment_date' => Carbon::now('Asia/Kolkata')->addDay()->toDateString(),
            'appointment_time' => '15:00',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'status' => 'confirmed',
        ], $overrides);
    }

    public function test_combo_duration_is_saved_and_exposed(): void
    {
        $this->actingAsToken($this->superadmin());
        $product = Product::factory()->create();

        $id = $this->postJson('/api/admin/combos', [
            'name' => 'Hair Spa Combo',
            'bundle_price' => 1999,
            'duration_minutes' => 75,
            'items' => [['product_id' => $product->id, 'price' => 1999]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.duration_minutes', 75)
            ->json('data.id');

        $this->putJson("/api/admin/combos/{$id}", ['duration_minutes' => 120])
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 120);

        $this->getJson('/api/combos')->assertOk()->assertJsonPath('data.0.duration_minutes', 120);

        $this->postJson('/api/admin/combos', [
            'name' => 'Bad',
            'duration_minutes' => 0,
            'items' => [['product_id' => $product->id, 'price' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors('duration_minutes');
    }

    public function test_pricing_plan_has_its_own_price_and_duration(): void
    {
        $this->actingAsToken($this->superadmin());

        $id = $this->postJson('/api/admin/pricing-plans', [
            'name' => 'Gold', 'price' => 2500, 'duration_minutes' => 60,
        ])
            ->assertCreated()
            ->assertJsonPath('data.duration_minutes', 60)
            ->json('data.id');

        $this->putJson("/api/admin/pricing-plans/{$id}", ['duration_minutes' => 45])
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 45);

        // Toggling status alone leaves the duration untouched.
        $this->putJson("/api/admin/pricing-plans/{$id}", ['status' => false])->assertOk();
        $this->assertSame(45, PricingPlan::find($id)->duration_minutes);
    }

    public function test_offline_combo_offer_appointment_snapshots_the_plans_price_and_duration(): void
    {
        $this->actingAsToken($this->superadmin());
        $plan = $this->plan();
        // A product combo with the same name must not feed the booking.
        Combo::create(['name' => 'Bridal Glow Package', 'slug' => 'bgp', 'bundle_price' => 1, 'duration_minutes' => 15]);
        $stylist = Stylist::factory()->create();

        $this->postJson('/api/admin/appointments', $this->payload($plan, $stylist))
            ->assertCreated()
            ->assertJsonPath('data.source', 'offline')
            ->assertJsonPath('data.pricing_plan_id', $plan->id)
            ->assertJsonPath('data.service_id', null)
            ->assertJsonPath('data.category_name', 'Combo Offer')
            ->assertJsonPath('data.service_name', 'Bridal Glow Package')
            ->assertJsonPath('data.duration_minutes', 90)
            ->assertJsonPath('data.appointment_end_time', '16:30')
            ->assertJsonPath('data.service_price', fn ($v) => (float) $v === 4999.0)
            ->assertJsonPath('data.advance_percentage', 0)
            ->assertJsonPath('data.amount_received', fn ($v) => (float) $v === 4999.0);

        // All Appointments and History read the same endpoint.
        $this->getJson('/api/admin/appointments')
            ->assertOk()
            ->assertJsonPath('data.0.category_name', 'Combo Offer')
            ->assertJsonPath('data.0.service_name', 'Bridal Glow Package');
        $this->getJson('/api/admin/appointments?sort=created_at&direction=desc&source=offline&search=Combo+Offer')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_plan_duration_is_used_for_the_slot_conflict_check(): void
    {
        $this->actingAsToken($this->superadmin());
        $plan = $this->plan();
        $stylist = Stylist::factory()->create();
        $date = Carbon::now('Asia/Kolkata')->addDay()->toDateString();

        Appointment::factory()->create([
            'stylist_id' => $stylist->id,
            'appointment_date' => $date,
            'appointment_time' => '16:00',
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        // 15:00 + 90 min runs into the 16:00 booking.
        $this->postJson('/api/admin/appointments', $this->payload($plan, $stylist, ['appointment_date' => $date]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('appointment_time');

        // 14:00 + 90 min ends 15:30 — free, and then that window is locked too.
        $this->postJson('/api/admin/appointments', $this->payload($plan, $stylist, [
            'appointment_date' => $date, 'appointment_time' => '14:00',
        ]))->assertCreated();

        $this->postJson('/api/admin/appointments', $this->payload($plan, $stylist, [
            'appointment_date' => $date, 'appointment_time' => '15:00',
        ]))->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_combo_offer_needs_an_active_plan_with_a_duration(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();

        $this->postJson('/api/admin/appointments', $this->payload($this->plan(['duration_minutes' => null]), $stylist))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pricing_plan_id');

        $this->postJson('/api/admin/appointments', $this->payload($this->plan(['status' => false]), $stylist))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pricing_plan_id');

        $this->postJson('/api/admin/appointments', $this->payload($this->plan(), $stylist, ['pricing_plan_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id', 'service_id']);
    }

    public function test_combo_offer_slots_are_sized_by_the_plan_duration(): void
    {
        $this->actingAsToken($this->superadmin());
        $plan = $this->plan();
        $stylist = $this->giveWorkHours(Stylist::factory()->create());
        $date = Carbon::now('Asia/Kolkata')->addDay()->toDateString();
        $url = "/api/admin/appointments/combo-slots?pricing_plan_id={$plan->id}&stylist_id={$stylist->id}&date={$date}";

        $slots = $this->getJson($url)->assertOk()->json('data.slots');

        $this->assertNotEmpty($slots);
        foreach ($slots as $slot) {
            $this->assertSame(90, (int) Carbon::parse($slot['start'])->diffInMinutes(Carbon::parse($slot['end'])));
        }

        $this->actingAsToken($this->userWith(['appointments.view']));
        $this->getJson($url)->assertForbidden();
    }
}
