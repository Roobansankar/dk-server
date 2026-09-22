<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Stylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/appointments/busy — the read-only feed the public booking form
 * uses to grey out "Preferred time" slots. Deliberately narrow: only start
 * and end, only for CONFIRMED appointments, only for the requested stylist.
 */
class AppointmentBusySlotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_confirmed_window_for_the_requested_stylist_and_date(): void
    {
        $stylist = Stylist::factory()->create();
        Appointment::factory()->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-12-15',
            'appointment_time' => '10:00',
            'duration_minutes' => 60,
        ]);

        $response = $this->getJson("/api/appointments/busy?stylist_id={$stylist->id}&date=2026-12-15");

        $response->assertOk()->assertExactJson([
            'data' => [['start' => '10:00', 'end' => '11:00']],
        ]);
    }

    public function test_it_ignores_pending_and_cancelled_appointments(): void
    {
        $stylist = Stylist::factory()->create();
        Appointment::factory()->forStylist($stylist)->pending()->create([
            'appointment_date' => '2026-12-15',
            'appointment_time' => '10:00',
            'duration_minutes' => 60,
        ]);
        Appointment::factory()->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CANCELLED,
            'appointment_date' => '2026-12-15',
            'appointment_time' => '11:00',
            'duration_minutes' => 60,
        ]);

        $response = $this->getJson("/api/appointments/busy?stylist_id={$stylist->id}&date=2026-12-15");

        $response->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_it_ignores_other_stylists_and_other_dates(): void
    {
        $stylist = Stylist::factory()->create();
        $otherStylist = Stylist::factory()->create();
        Appointment::factory()->forStylist($otherStylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-12-15',
            'appointment_time' => '10:00',
            'duration_minutes' => 60,
        ]);
        Appointment::factory()->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-12-16',
            'appointment_time' => '10:00',
            'duration_minutes' => 60,
        ]);

        $response = $this->getJson("/api/appointments/busy?stylist_id={$stylist->id}&date=2026-12-15");

        $response->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_it_does_not_expose_customer_details(): void
    {
        $stylist = Stylist::factory()->create();
        Appointment::factory()->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => '2026-12-15',
            'appointment_time' => '10:00',
            'duration_minutes' => 60,
            'customer_name' => 'Should Not Leak',
        ]);

        $response = $this->getJson("/api/appointments/busy?stylist_id={$stylist->id}&date=2026-12-15");

        $response->assertOk()
            ->assertJsonMissing(['customer_name' => 'Should Not Leak'])
            ->assertJsonStructure(['data' => [['start', 'end']]]);
    }

    public function test_it_requires_stylist_id_and_date(): void
    {
        $this->getJson('/api/appointments/busy')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stylist_id', 'date']);
    }

    public function test_it_rejects_an_unknown_stylist(): void
    {
        $this->getJson('/api/appointments/busy?stylist_id=999999&date=2026-12-15')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stylist_id']);
    }
}
