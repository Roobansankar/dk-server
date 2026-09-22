<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    private function activeService(): Service
    {
        $category = ServiceCategory::factory()->female()->create();

        return Service::factory()->forCategory($category)->create();
    }

    public function test_an_authenticated_customer_can_submit_an_appointment_request(): void
    {
        $this->actingAsToken($this->customer());
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

    public function test_appointment_requires_valid_service_and_future_date(): void
    {
        $this->actingAsToken($this->customer());
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
        $this->actingAsToken($this->customer());
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
        $this->actingAsToken($this->customer());
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
