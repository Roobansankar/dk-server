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

class CustomerAppointmentHistoryTest extends TestCase
{
    use CreatesAdmins, CreatesBookableStylists, RefreshDatabase;

    public function test_appointment_history_requires_authentication(): void
    {
        $this->getJson('/api/account/appointments')->assertUnauthorized();
    }

    public function test_a_customer_sees_only_their_own_appointments(): void
    {
        $customerA = $this->customer();
        $customerB = $this->customer();

        Appointment::factory()->count(2)->create(['user_id' => $customerA->id]);
        Appointment::factory()->count(3)->create(['user_id' => $customerB->id]);
        Appointment::factory()->create(['user_id' => null]); // guest booking

        $this->actingAsToken($customerA);
        $response = $this->getJson('/api/account/appointments')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertDatabaseCount('appointments', 6);
    }

    public function test_a_customer_cannot_see_another_customers_appointment_via_the_own_scoped_endpoint(): void
    {
        $customerA = $this->customer();
        $customerB = $this->customer();
        $bsAppointment = Appointment::factory()->create(['user_id' => $customerB->id]);

        $this->actingAsToken($customerA);
        $response = $this->getJson('/api/account/appointments')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($bsAppointment->id));
    }

    public function test_booking_while_authenticated_attaches_the_customers_user_id(): void
    {
        $customer = $this->customer();
        [$category, $service, $stylist] = $this->bookableCatalogue();
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/appointments', $this->bookingPayload($category, $service, $stylist));

        $response->assertCreated();
        $this->assertDatabaseHas('appointments', [
            'reference' => $response->json('data.reference'),
            'user_id' => $customer->id,
        ]);
    }

    public function test_an_unauthenticated_booking_attempt_is_rejected(): void
    {
        [$category, $service, $stylist] = $this->bookableCatalogue();

        $response = $this->postJson('/api/appointments', $this->bookingPayload($category, $service, $stylist));

        $response->assertUnauthorized();
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_an_invalid_bearer_token_is_also_rejected(): void
    {
        [$category, $service, $stylist] = $this->bookableCatalogue();

        $response = $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->postJson('/api/appointments', $this->bookingPayload($category, $service, $stylist));

        $response->assertUnauthorized();
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_a_customer_cannot_book_on_behalf_of_another_user_via_a_crafted_field(): void
    {
        $customer = $this->customer();
        $otherCustomer = $this->customer();
        [$category, $service, $stylist] = $this->bookableCatalogue();
        $token = $customer->createToken('t')->plainTextToken;

        $payload = $this->bookingPayload($category, $service, $stylist);
        // StoreAppointmentRequest has no `user_id` rule at all, so this is
        // silently ignored rather than mass-assigned — asserted below.
        $payload['user_id'] = $otherCustomer->id;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/appointments', $payload);

        $response->assertCreated();
        $this->assertDatabaseHas('appointments', [
            'reference' => $response->json('data.reference'),
            'user_id' => $customer->id,
        ]);
        $this->assertDatabaseMissing('appointments', [
            'reference' => $response->json('data.reference'),
            'user_id' => $otherCustomer->id,
        ]);
    }

    /** Guest appointments created before this requirement existed must remain valid/queryable. */
    public function test_a_preexisting_guest_appointment_record_remains_valid(): void
    {
        $legacyGuestAppointment = Appointment::factory()->create(['user_id' => null]);

        $this->assertDatabaseHas('appointments', ['id' => $legacyGuestAppointment->id, 'user_id' => null]);
    }

    /**
     * There is no customer-facing endpoint that mutates an appointment at
     * all (Api\Public\AccountController only reads); the only write paths
     * are Admin\AppointmentController, gated by `appointments.manage`. A
     * customer token — which carries no such permission — is rejected the
     * same way any non-staff request would be, so "reassign ownership" is
     * structurally unreachable, not just unvalidated.
     */
    public function test_a_customer_cannot_reach_the_admin_endpoint_to_modify_any_appointments_ownership(): void
    {
        $customerA = $this->customer();
        $customerB = $this->customer();
        $bsAppointment = Appointment::factory()->create(['user_id' => $customerB->id]);

        $this->actingAsToken($customerA);

        $this->patchJson("/api/admin/appointments/{$bsAppointment->id}", [
            'user_id' => $customerA->id,
        ])->assertForbidden();

        $this->assertDatabaseHas('appointments', ['id' => $bsAppointment->id, 'user_id' => $customerB->id]);
    }

    public function test_the_booked_appointment_then_appears_in_that_customers_history(): void
    {
        $customer = $this->customer();
        [$category, $service, $stylist] = $this->bookableCatalogue();
        $token = $customer->createToken('t')->plainTextToken;

        $created = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/appointments', $this->bookingPayload($category, $service, $stylist))
            ->assertCreated();

        $this->actingAsToken($customer);
        $history = $this->getJson('/api/account/appointments')->assertOk();

        $ids = collect($history->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($created->json('data.id')));
    }

    /** @return array{0: ServiceCategory, 1: Service, 2: Stylist} */
    private function bookableCatalogue(): array
    {
        $category = ServiceCategory::factory()->female()->create();
        $service = Service::factory()->forCategory($category)->create([
            'duration_minutes' => 30,
            'advance_percentage' => 0,
        ]);
        $stylist = $this->bookableStylistFor($service);

        return [$category, $service, $stylist];
    }

    private function bookingPayload(ServiceCategory $category, Service $service, Stylist $stylist): array
    {
        return [
            'customer_name' => 'Test Customer',
            'phone' => '9876543210',
            'gender' => 'female',
            'category_id' => $category->id,
            'service_id' => $service->id,
            'stylist_id' => $stylist->id,
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '11:00',
        ];
    }
}
