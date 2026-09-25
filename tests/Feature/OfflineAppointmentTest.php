<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Stylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\Concerns\CreatesBookableStylists;
use Tests\TestCase;

class OfflineAppointmentTest extends TestCase
{
    use CreatesAdmins, CreatesBookableStylists, RefreshDatabase;

    private function activeService(): Service
    {
        $category = ServiceCategory::factory()->female()->create();

        return Service::factory()->forCategory($category)->create([
            'duration_minutes' => 45, 'price' => 1200, 'advance_percentage' => 25,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        $service = $overrides['_service'] ?? $this->activeService();
        unset($overrides['_service']);

        return array_merge([
            'customer_name' => 'Walk-in Guest',
            'phone' => '+91 9876543210',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '15:30',
            'payment_status' => 'advance_paid',
            'status' => 'confirmed',
        ], $overrides);
    }

    public function test_staff_can_create_an_offline_appointment_with_snapshots(): void
    {
        $this->actingAsToken($this->superadmin());
        $service = $this->activeService();
        $stylist = Stylist::factory()->create(['name' => 'Devi K']);

        $this->postJson('/api/admin/appointments', $this->payload([
            '_service' => $service,
            'stylist_id' => $stylist->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.source', 'offline')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'advance_paid')
            ->assertJsonPath('data.service_name', $service->name)
            ->assertJsonPath('data.stylist_name', 'Devi K')
            ->assertJsonPath('data.duration_minutes', 45)
            ->assertJsonPath('data.service_price', fn ($v) => (float) $v === 1200.0)
            ->assertJsonPath('data.advance_amount', fn ($v) => (float) $v === 300.0);

        $this->assertDatabaseHas('appointments', [
            'source' => 'offline',
            'stylist_id' => $stylist->id,
            'stylist_name' => 'Devi K',
            'service_id' => $service->id,
        ]);
    }

    public function test_offline_creation_requires_the_offline_permission(): void
    {
        $this->actingAsToken($this->userWith(['appointments.view', 'appointments.manage']));

        $this->postJson('/api/admin/appointments', $this->payload())->assertForbidden();
    }

    public function test_offline_appointments_are_isolable_by_source_but_still_appear_in_the_full_list(): void
    {
        $this->actingAsToken($this->superadmin());
        $service = $this->activeService();

        $this->postJson('/api/admin/appointments', $this->payload(['_service' => $service]))->assertCreated();
        Appointment::factory()->forService($service)->create(); // online

        $this->getJson('/api/admin/appointments?source=offline')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source', 'offline');

        $this->getJson('/api/admin/appointments')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_public_booking_defaults_to_online_and_can_carry_a_stylist(): void
    {
        $this->actingAsToken($this->customer());
        $service = $this->activeService();
        $stylist = Stylist::factory()->create(['name' => 'Karan M']);
        $this->offerServices($stylist, $service);

        $this->postJson('/api/appointments', [
            'customer_name' => 'Priya R',
            'phone' => '+91 9790431212',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'stylist_id' => $stylist->id,
            'appointment_date' => now()->addDays(2)->toDateString(),
            'appointment_time' => '14:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.source', 'online')
            ->assertJsonPath('data.stylist_name', 'Karan M');
    }
}
