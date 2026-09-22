<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Stylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

/**
 * Strict server-side slot locking. A confirmed appointment locks its full
 * service duration for its stylist; overlapping confirmations are rejected.
 */
class AppointmentSlotLockTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    private string $date = '2026-10-05';

    private function service(int $minutes): Service
    {
        $category = ServiceCategory::factory()->female()->create();

        return Service::factory()->forCategory($category)->create([
            'duration_minutes' => $minutes,
            'price' => 1200,
            'advance_percentage' => 25,
        ]);
    }

    /** A confirmed 2:00–3:30 PM appointment (90-minute service) for $stylist. */
    private function confirmedAnchor(Stylist $stylist): Appointment
    {
        return Appointment::factory()
            ->forService($this->service(90))
            ->forStylist($stylist)
            ->create([
                'status' => Appointment::STATUS_CONFIRMED,
                'appointment_date' => $this->date,
                'appointment_time' => '14:00',
            ]);
    }

    private function pending(Stylist $stylist, string $time, int $minutes): Appointment
    {
        return Appointment::factory()
            ->forService($this->service($minutes))
            ->forStylist($stylist)
            ->pending()
            ->create([
                'appointment_date' => $this->date,
                'appointment_time' => $time,
            ]);
    }

    private function confirm(Appointment $appointment)
    {
        return $this->postJson("/api/admin/appointments/{$appointment->id}/confirm");
    }

    // --- The explicit conflict matrix (confirmed 2:00–3:30, same stylist) -----

    public function test_same_start_shorter_service_is_blocked(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $this->confirmedAnchor($stylist);

        // 2:00–3:00
        $this->confirm($this->pending($stylist, '14:00', 60))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_new_booking_ending_inside_is_blocked(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $this->confirmedAnchor($stylist);

        // 2:30–3:30
        $this->confirm($this->pending($stylist, '14:30', 60))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_new_booking_starting_before_and_ending_inside_is_blocked(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $this->confirmedAnchor($stylist);

        // 1:30–2:30
        $this->confirm($this->pending($stylist, '13:30', 60))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_new_booking_fully_containing_the_existing_one_is_blocked(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $this->confirmedAnchor($stylist);

        // 1:00–4:00
        $this->confirm($this->pending($stylist, '13:00', 180))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_booking_starting_exactly_at_the_end_is_allowed(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $this->confirmedAnchor($stylist);

        // 3:30–4:30
        $this->confirm($this->pending($stylist, '15:30', 60))
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_booking_ending_exactly_at_the_start_is_allowed(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $this->confirmedAnchor($stylist);

        // 12:30–2:00
        $this->confirm($this->pending($stylist, '12:30', 90))
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_a_different_stylist_at_the_same_time_is_allowed(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $other = Stylist::factory()->create();
        $this->confirmedAnchor($stylist);

        $this->confirm($this->pending($other, '14:00', 90))
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_an_unassigned_stylist_booking_is_not_range_checked(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $this->confirmedAnchor($stylist);

        $noStylist = Appointment::factory()
            ->forService($this->service(90))
            ->pending()
            ->create(['appointment_date' => $this->date, 'appointment_time' => '14:00']);

        $this->confirm($noStylist)->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    // --- Confirm behaviour --------------------------------------------------

    public function test_confirm_returns_end_time_and_can_record_payment(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $appointment = $this->pending($stylist, '11:00', 45);

        $this->postJson("/api/admin/appointments/{$appointment->id}/confirm", [
            'payment_status' => 'advance_paid',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'advance_paid')
            ->assertJsonPath('data.appointment_time', '11:00')
            ->assertJsonPath('data.appointment_end_time', '11:45');
    }

    public function test_two_overlapping_pending_appointments_cannot_both_be_confirmed(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();

        $first = $this->pending($stylist, '14:00', 90);
        $second = $this->pending($stylist, '15:00', 60);

        $this->confirm($first)->assertOk();
        $this->confirm($second)->assertStatus(422)->assertJsonValidationErrors('appointment_time');

        $this->assertDatabaseHas('appointments', ['id' => $second->id, 'status' => 'pending']);
    }

    public function test_a_non_pending_appointment_cannot_be_confirmed(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();

        $completed = Appointment::factory()->forService($this->service(60))->forStylist($stylist)->create([
            'status' => Appointment::STATUS_COMPLETED,
            'appointment_date' => $this->date,
            'appointment_time' => '09:00',
        ]);

        $this->confirm($completed)->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_confirm_requires_manage_permission(): void
    {
        $this->actingAsToken($this->userWith(['appointments.view']));
        $stylist = Stylist::factory()->create();
        $appointment = $this->pending($stylist, '10:00', 30);

        $this->confirm($appointment)->assertForbidden();
    }

    // --- The other two write paths ---------------------------------------

    public function test_public_booking_is_rejected_when_it_overlaps_a_confirmed_appointment(): void
    {
        $this->actingAsToken($this->customer());
        $stylist = Stylist::factory()->create();
        $service = $this->service(90);

        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => $this->date,
            'appointment_time' => '14:00',
        ]);

        $this->postJson('/api/appointments', [
            'customer_name' => 'Priya R',
            'phone' => '+91 9790431212',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'stylist_id' => $stylist->id,
            'appointment_date' => $this->date,
            'appointment_time' => '14:30',
        ])->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_public_booking_with_no_stylist_is_not_blocked(): void
    {
        $this->actingAsToken($this->customer());
        $stylist = Stylist::factory()->create();
        $service = $this->service(90);

        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => $this->date,
            'appointment_time' => '14:00',
        ]);

        $this->postJson('/api/appointments', [
            'customer_name' => 'Priya R',
            'phone' => '+91 9790431212',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'appointment_date' => $this->date,
            'appointment_time' => '14:30',
        ])->assertCreated();
    }

    public function test_offline_confirmed_creation_is_rejected_when_it_overlaps(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $service = $this->service(90);

        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => $this->date,
            'appointment_time' => '14:00',
        ]);

        $this->postJson('/api/admin/appointments', [
            'customer_name' => 'Walk-in',
            'phone' => '+91 9876543210',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'stylist_id' => $stylist->id,
            'appointment_date' => $this->date,
            'appointment_time' => '15:00',
            'status' => 'confirmed',
        ])->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_offline_pending_creation_is_allowed_even_when_it_overlaps(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $service = $this->service(90);

        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => $this->date,
            'appointment_time' => '14:00',
        ]);

        $this->postJson('/api/admin/appointments', [
            'customer_name' => 'Walk-in',
            'phone' => '+91 9876543210',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'stylist_id' => $stylist->id,
            'appointment_date' => $this->date,
            'appointment_time' => '15:00',
            'status' => 'pending',
        ])->assertCreated();
    }
}
