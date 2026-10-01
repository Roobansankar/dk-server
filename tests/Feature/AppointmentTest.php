<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\Concerns\CreatesBookableStylists;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use CreatesAdmins, CreatesBookableStylists, RefreshDatabase;

    private function activeService(): Service
    {
        $category = ServiceCategory::factory()->female()->create();
        $service = Service::factory()->forCategory($category)->create();

        // "Any professional" needs someone who offers the service.
        $this->bookableStylistFor($service);

        return $service;
    }

    public function test_an_appointment_request_snapshots_the_service_configuration(): void
    {
        $service = $this->activeService();
        $service->update(['duration_minutes' => 60, 'price' => 2000, 'advance_percentage' => 25]);

        $response = $this->postJson('/api/appointments', [
            'customer_name' => 'Priya R',
            'phone' => '+91 9790431212',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'appointment_date' => now()->addDays(2)->toDateString(),
            'appointment_time' => '14:00',
            'message' => 'Prefer afternoon',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.service_name', $service->name)
            // service configuration is snapshotted onto the appointment
            ->assertJsonPath('data.duration_minutes', 60)
            ->assertJsonPath('data.service_price', fn ($v) => (float) $v === 2000.0)
            ->assertJsonPath('data.advance_percentage', 25)
            ->assertJsonPath('data.advance_amount', fn ($v) => (float) $v === 500.0)
            ->assertJsonPath('data.remaining_amount', fn ($v) => (float) $v === 2000.0)
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->assertDatabaseHas('appointments', [
            'phone' => '+91 9790431212',
            'service_id' => $service->id,
            'category_name' => $service->category->name,
            'service_price' => 2000,
            'advance_amount' => 500,
            'status' => 'pending',
        ]);
    }

    public function test_a_guest_can_submit_an_appointment_request(): void
    {
        $service = $this->activeService();
        $service->update(['duration_minutes' => 60, 'price' => 2000, 'advance_percentage' => 25]);

        $response = $this->postJson('/api/appointments', [
            'customer_name' => 'Guest Customer',
            'phone' => '+91 9790431213',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'appointment_date' => now()->addDays(2)->toDateString(),
            'appointment_time' => '14:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.source', 'online')
            ->assertJsonPath('data.customer_name', 'Guest Customer')
            ->assertJsonPath('data.service_name', $service->name)
            ->assertJsonPath('data.duration_minutes', 60)
            ->assertJsonPath('data.advance_amount', fn ($v) => (float) $v === 500.0)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonMissingPath('data.notes');

        $this->assertDatabaseHas('appointments', [
            'reference' => $response->json('data.reference'),
            'user_id' => null,
            'customer_name' => 'Guest Customer',
            'phone' => '+91 9790431213',
            'service_id' => $service->id,
            'status' => 'pending',
        ]);
    }

    public function test_a_guest_booking_is_still_validated(): void
    {
        $service = $this->activeService();

        $this->postJson('/api/appointments', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_name', 'phone', 'gender', 'category_id', 'appointment_date', 'appointment_time']);

        $this->postJson('/api/appointments', [
            'customer_name' => 'X',
            'phone' => 'not-a-phone',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'appointment_date' => now()->subDay()->toDateString(),
            'appointment_time' => '2pm',
        ])->assertStatus(422)->assertJsonValidationErrors(['phone', 'appointment_date', 'appointment_time']);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_a_guest_cannot_book_a_slot_that_is_already_taken(): void
    {
        $category = ServiceCategory::factory()->female()->create();
        $service = Service::factory()->forCategory($category)->create(['duration_minutes' => 60]);
        $stylist = $this->bookableStylistFor($service);
        $date = now()->addDays(2)->toDateString();

        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => $date,
            'appointment_time' => '14:00',
        ]);

        $payload = [
            'customer_name' => 'Guest Customer',
            'phone' => '+91 9790431213',
            'gender' => 'female',
            'category_id' => $category->id,
            'service_id' => $service->id,
            'stylist_id' => $stylist->id,
            'appointment_date' => $date,
        ];

        // Overlaps the confirmed 14:00–15:00 appointment.
        $this->postJson('/api/appointments', $payload + ['appointment_time' => '14:30'])
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');

        // Inside the professional's 1–2 PM break.
        $this->postJson('/api/appointments', $payload + ['appointment_time' => '13:00'])
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_admin_appointment_endpoints_still_reject_guests_and_customers(): void
    {
        $appointment = Appointment::factory()->pending()->create();

        $this->getJson('/api/admin/appointments')->assertUnauthorized();
        $this->getJson("/api/admin/appointments/{$appointment->id}")->assertUnauthorized();
        $this->postJson('/api/admin/appointments', [])->assertUnauthorized();
        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['status' => 'confirmed'])->assertUnauthorized();
        $this->postJson("/api/admin/appointments/{$appointment->id}/confirm")->assertUnauthorized();
        $this->deleteJson("/api/admin/appointments/{$appointment->id}")->assertUnauthorized();

        // A legacy customer row carries no staff permission either.
        $this->actingAsToken($this->customer());

        $this->getJson('/api/admin/appointments')->assertForbidden();
        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['status' => 'confirmed'])->assertForbidden();
        $this->deleteJson("/api/admin/appointments/{$appointment->id}")->assertForbidden();

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'pending']);
    }

    public function test_appointment_requires_valid_service_and_future_date(): void
    {
        $service = $this->activeService();

        $this->postJson('/api/appointments', [
            'customer_name' => 'X',
            'phone' => '123456',
            'gender' => 'male',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'appointment_date' => now()->subDay()->toDateString(),
            'appointment_time' => '14:00',
        ])->assertStatus(422)->assertJsonValidationErrors('appointment_date');
    }

    public function test_service_must_belong_to_the_given_category(): void
    {
        $service = $this->activeService();
        $otherCategory = ServiceCategory::factory()->create();

        $this->postJson('/api/appointments', [
            'customer_name' => 'X',
            'phone' => '123456',
            'gender' => 'male',
            'category_id' => $otherCategory->id,
            'service_id' => $service->id,
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '14:00',
        ])->assertStatus(422)->assertJsonValidationErrors('service_id');
    }

    public function test_admin_can_move_an_appointment_through_statuses(): void
    {
        $this->actingAsToken($this->superadmin());
        $appointment = Appointment::factory()->pending()->create();

        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['status' => 'confirmed'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['status' => 'completed', 'notes' => 'Regular client'])
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'completed', 'notes' => 'Regular client']);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $this->actingAsToken($this->superadmin());
        $appointment = Appointment::factory()->create();

        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['status' => 'archived'])
            ->assertStatus(422);
    }

    public function test_appointment_listing_can_be_filtered_and_searched(): void
    {
        $this->actingAsToken($this->superadmin());
        Appointment::factory()->create(['status' => 'pending', 'customer_name' => 'Aisha K']);
        Appointment::factory()->create(['status' => 'confirmed', 'customer_name' => 'Ben T']);

        $this->getJson('/api/admin/appointments?status=pending')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/appointments?search=Aisha')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_history_view_filters_by_gender_service_and_sorts(): void
    {
        $this->actingAsToken($this->superadmin());
        $service = $this->activeService();

        Appointment::factory()->forService($service)->create([
            'gender' => 'female', 'customer_name' => 'Fiona', 'appointment_date' => '2026-01-10',
        ]);
        Appointment::factory()->create([
            'gender' => 'male', 'customer_name' => 'Mo', 'service_name' => 'Barber Cut',
            'appointment_date' => '2026-02-20',
        ]);

        $this->getJson('/api/admin/appointments?gender=female')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_name', 'Fiona');

        $this->getJson('/api/admin/appointments?service_id='.$service->id)
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/admin/appointments?search=Barber')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_name', 'Mo');

        $oldestFirst = $this->getJson('/api/admin/appointments?sort=appointment_date&direction=asc')
            ->assertOk()->json('data');
        $this->assertSame('Fiona', $oldestFirst[0]['customer_name']);
    }

    public function test_admin_can_record_a_payment_status(): void
    {
        $this->actingAsToken($this->superadmin());
        $service = $this->activeService();
        $service->update(['price' => 2000, 'advance_percentage' => 25]);

        $appointment = Appointment::factory()->forService($service)->create([
            'status' => 'completed', 'payment_status' => 'unpaid',
        ]);

        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['payment_status' => 'advance_paid'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'advance_paid')
            ->assertJsonPath('data.amount_received', fn ($v) => (float) $v === 500.0)
            ->assertJsonPath('data.remaining_amount', fn ($v) => (float) $v === 1500.0);

        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['payment_status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('data.remaining_amount', fn ($v) => (float) $v === 0.0);

        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['payment_status' => 'nonsense'])
            ->assertStatus(422);
    }

    public function test_internal_notes_are_not_exposed_publicly(): void
    {
        $service = $this->activeService();

        $json = $this->postJson('/api/appointments', [
            'customer_name' => 'Priya R',
            'phone' => '+91 9790431212',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'appointment_date' => now()->addDays(2)->toDateString(),
            'appointment_time' => '14:00',
        ])->assertCreated();

        $json->assertJsonMissingPath('data.notes');
    }

    public function test_admin_can_delete_an_appointment(): void
    {
        $this->actingAsToken($this->superadmin());
        $appointment = Appointment::factory()->pending()->create();

        $this->deleteJson("/api/admin/appointments/{$appointment->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
    }

    public function test_the_admin_role_can_delete_an_appointment(): void
    {
        $this->actingAsToken($this->admin());
        $appointment = Appointment::factory()->create();

        $this->deleteJson("/api/admin/appointments/{$appointment->id}")->assertNoContent();
        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
    }

    public function test_deleting_an_appointment_requires_the_manage_permission(): void
    {
        $this->actingAsToken($this->userWith(['appointments.view']));
        $appointment = Appointment::factory()->create();

        $this->deleteJson("/api/admin/appointments/{$appointment->id}")->assertForbidden();
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
    }

    public function test_deleting_a_missing_appointment_returns_404(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->deleteJson('/api/admin/appointments/999999')->assertNotFound();
    }

    public function test_deleting_an_appointment_leaves_other_records_untouched(): void
    {
        $this->actingAsToken($this->superadmin());
        $keep = Appointment::factory()->create();
        $drop = Appointment::factory()->create();

        $this->deleteJson("/api/admin/appointments/{$drop->id}")->assertNoContent();

        $this->assertDatabaseMissing('appointments', ['id' => $drop->id]);
        $this->assertDatabaseHas('appointments', ['id' => $keep->id]);
        $this->getJson('/api/admin/appointments')->assertOk()->assertJsonCount(1, 'data');
    }
}
