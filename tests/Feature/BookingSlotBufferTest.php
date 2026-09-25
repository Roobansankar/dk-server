<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SiteSetting;
use App\Models\Stylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesAdmins;
use Tests\Concerns\CreatesBookableStylists;
use Tests\TestCase;

/**
 * Server-side authority for the dynamic, duration-based booking picker: the
 * 10-minute inter-session buffer, the fixed 1–2 PM break, and rejecting a
 * start time that has already passed (studio time, Asia/Kolkata). These sit
 * alongside AppointmentSlotLockTest (raw overlap matrix, no buffer — used by
 * the admin/offline paths) and ShopHoursTest (opening/closing bounds).
 */
class BookingSlotBufferTest extends TestCase
{
    use CreatesAdmins, CreatesBookableStylists, RefreshDatabase;

    private string $date = '2026-10-05';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->setHours('10:00', '19:30');
        // Every booking in this file goes through the online endpoint, which
        // now requires a signed-in customer — the buffer/break/closing rules
        // under test here are orthogonal to that gate.
        $this->actingAsToken($this->customer());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setHours(string $open, string $close): void
    {
        SiteSetting::query()->updateOrCreate(['key' => 'shop_opens_at'], ['value' => $open, 'type' => 'time', 'group' => 'shop_hours']);
        SiteSetting::query()->updateOrCreate(['key' => 'shop_closes_at'], ['value' => $close, 'type' => 'time', 'group' => 'shop_hours']);
    }

    private function service(int $minutes): Service
    {
        $category = ServiceCategory::factory()->female()->create();

        return Service::factory()->forCategory($category)->create(['duration_minutes' => $minutes]);
    }

    private function book(Service $service, Stylist $stylist, string $time, ?string $date = null): TestResponse
    {
        $this->offerServices($stylist, $service);

        return $this->postJson('/api/appointments', [
            'customer_name' => 'Priya R',
            'phone' => '+91 9790431212',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'stylist_id' => $stylist->id,
            'appointment_date' => $date ?? $this->date,
            'appointment_time' => $time,
        ]);
    }

    // --- 10-minute inter-session buffer --------------------------------

    public function test_booking_within_the_buffer_after_a_confirmed_appointment_is_rejected(): void
    {
        $stylist = Stylist::factory()->create();
        $service = $this->service(40);

        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => $this->date,
            'appointment_time' => '17:30', // 5:30–6:10 PM
        ]);

        // 6:15 is only 5 minutes after the 6:10 end — inside the 10-minute buffer.
        $this->book($service, $stylist, '18:15')
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_booking_exactly_at_the_buffer_boundary_is_allowed(): void
    {
        $stylist = Stylist::factory()->create();
        $service = $this->service(40);

        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => $this->date,
            'appointment_time' => '17:30', // 5:30–6:10 PM
        ]);

        // 6:20 is exactly end (6:10) + 10-minute buffer.
        $this->book($service, $stylist, '18:20')->assertCreated();
    }

    public function test_booking_within_the_buffer_before_a_confirmed_appointment_is_rejected(): void
    {
        $stylist = Stylist::factory()->create();
        $service = $this->service(30);

        Appointment::factory()->forService($this->service(60))->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => $this->date,
            'appointment_time' => '15:00',
        ]);

        // 2:35–3:05 ends only 5 minutes before the 3:00 start.
        $this->book($service, $stylist, '14:35')
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_a_different_stylist_is_not_buffered_by_someone_elses_booking(): void
    {
        $stylist = Stylist::factory()->create();
        $other = Stylist::factory()->create();
        $service = $this->service(40);

        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => $this->date,
            'appointment_time' => '17:30',
        ]);

        $this->book($service, $other, '18:15')->assertCreated();
    }

    // --- Fixed 1–2 PM break ---------------------------------------------

    public function test_booking_starting_inside_the_break_is_rejected(): void
    {
        $stylist = Stylist::factory()->create();
        $service = $this->service(30);

        $this->book($service, $stylist, '13:15')
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_booking_crossing_into_the_break_is_rejected(): void
    {
        $stylist = Stylist::factory()->create();
        $service = $this->service(30);

        // 12:45–1:15 runs through the start of the break.
        $this->book($service, $stylist, '12:45')
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_booking_ending_exactly_at_the_break_start_is_allowed(): void
    {
        $stylist = Stylist::factory()->create();
        $service = $this->service(30);

        $this->book($service, $stylist, '12:30')->assertCreated();
    }

    public function test_booking_starting_exactly_at_the_break_end_is_allowed(): void
    {
        $stylist = Stylist::factory()->create();
        $service = $this->service(30);

        $this->book($service, $stylist, '14:00')->assertCreated();
    }

    // --- Full duration must fit before closing ---------------------------

    public function test_booking_whose_end_would_run_past_closing_is_rejected(): void
    {
        $this->setHours('10:00', '19:30');
        $stylist = Stylist::factory()->create();
        $service = $this->service(90);

        // 18:15 + 90min = 19:45, past the 19:30 close.
        $this->book($service, $stylist, '18:15')
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_booking_ending_exactly_at_closing_is_allowed(): void
    {
        $this->setHours('10:00', '19:30');
        $stylist = Stylist::factory()->create();
        $service = $this->service(90);

        $this->book($service, $stylist, '18:00')->assertCreated();
    }

    // --- Past-time rejection (studio time, Asia/Kolkata) ------------------

    public function test_booking_a_time_that_has_already_passed_today_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 15:00', 'Asia/Kolkata'));
        $stylist = Stylist::factory()->create();
        $service = $this->service(30);

        $this->book($service, $stylist, '14:30', '2026-10-05')
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_booking_a_future_time_today_is_allowed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 15:00', 'Asia/Kolkata'));
        $stylist = Stylist::factory()->create();
        $service = $this->service(30);

        $this->book($service, $stylist, '15:30', '2026-10-05')->assertCreated();
    }

    public function test_booking_todays_time_on_a_future_date_is_unaffected_by_the_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 23:00', 'Asia/Kolkata'));
        $stylist = Stylist::factory()->create();
        $service = $this->service(30);

        $this->book($service, $stylist, '10:30', '2026-10-06')->assertCreated();
    }
}
