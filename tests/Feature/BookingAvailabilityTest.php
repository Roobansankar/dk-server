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
 * Per-professional booking: a professional only takes services they offer,
 * only inside the hours set for them on that calendar date (a date nobody set
 * is not bookable), and "any professional" resolves to a real, free, eligible
 * person. The public slot list and the appointment endpoint are checked
 * against the same rules.
 */
class BookingAvailabilityTest extends TestCase
{
    use CreatesAdmins, CreatesBookableStylists, RefreshDatabase;

    private string $monday;

    private string $tuesday;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->setShopHours('10:00', '19:30');

        $next = Carbon::now('Asia/Kolkata')->next(Carbon::MONDAY);
        $this->monday = $next->toDateString();
        $this->tuesday = $next->copy()->addDay()->toDateString();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setShopHours(string $open, string $close): void
    {
        SiteSetting::query()->updateOrCreate(['key' => 'shop_opens_at'], ['value' => $open, 'type' => 'time', 'group' => 'shop_hours']);
        SiteSetting::query()->updateOrCreate(['key' => 'shop_closes_at'], ['value' => $close, 'type' => 'time', 'group' => 'shop_hours']);
        Cache::flush();
    }

    private function service(int $minutes = 60, string $gender = 'female'): Service
    {
        $category = $gender === 'male'
            ? ServiceCategory::factory()->male()->create()
            : ServiceCategory::factory()->female()->create();

        return Service::factory()->forCategory($category)->create(['duration_minutes' => $minutes, 'price' => 1000, 'advance_percentage' => 0]);
    }

    /** A professional who offers $services and has hours set only on $this->monday, in the given ranges. */
    private function mondayStylist(array $ranges, Service ...$services): Stylist
    {
        $stylist = Stylist::factory()->create();
        $stylist->services()->attach(collect($services)->pluck('id')->all());

        foreach ($ranges as [$start, $end]) {
            $stylist->dateHours()->create(['date' => $this->monday, 'start_time' => $start, 'end_time' => $end]);
        }

        return $stylist;
    }

    private function slots(Service $service, string $date, ?Stylist $stylist = null): TestResponse
    {
        return $this->getJson('/api/booking/slots?'.http_build_query(array_filter([
            'service_id' => $service->id,
            'date' => $date,
            'stylist_id' => $stylist?->id,
        ])));
    }

    private function availableStarts(TestResponse $response): array
    {
        return collect($response->json('data.slots'))->where('status', 'available')->pluck('start')->values()->all();
    }

    private function book(Service $service, string $date, string $time, ?Stylist $stylist = null): TestResponse
    {
        $this->actingAsToken($this->customer());

        return $this->postJson('/api/appointments', array_filter([
            'customer_name' => 'Priya R',
            'phone' => '+91 9790431212',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'stylist_id' => $stylist?->id,
            'appointment_date' => $date,
            'appointment_time' => $time,
        ]));
    }

    // --- The public roster carries what each professional does ---------------

    public function test_the_public_roster_lists_each_professionals_services_and_dates(): void
    {
        $offered = $this->service();
        $hidden = Service::factory()->forCategory(ServiceCategory::factory()->female()->create())->inactive()->create();
        $ready = $this->mondayStylist([['10:00', '13:00'], ['14:00', '18:00']], $offered, $hidden);
        $bare = Stylist::factory()->create();

        $rows = collect($this->getJson('/api/stylists')->assertOk()->json('data'))->keyBy('id');

        // only ACTIVE services count, and only the date that was set is listed
        $this->assertSame([$offered->id], $rows[$ready->id]['service_ids']);
        $this->assertSame(
            [$this->monday => [['start' => '10:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '18:00']]],
            $rows[$ready->id]['date_hours'],
        );
        $this->assertArrayNotHasKey('work_hours', $rows[$ready->id]);
        $this->assertTrue($rows[$ready->id]['bookable']);
        $this->assertFalse($rows[$bare->id]['bookable']);
    }

    public function test_a_professional_with_services_but_no_dates_is_not_bookable(): void
    {
        $service = $this->service(60);
        $stylist = Stylist::factory()->create();
        $stylist->services()->attach($service->id);

        $row = collect($this->getJson('/api/stylists')->assertOk()->json('data'))->firstWhere('id', $stylist->id);

        $this->assertFalse($row['bookable']);
        $this->assertSame([], $row['date_hours']);
        $this->assertSame([], $this->availableStarts($this->slots($service, $this->monday, $stylist)));
        $this->assertSame([], $this->availableStarts($this->slots($service, $this->monday)));
        $this->book($service, $this->monday, '11:00', $stylist)->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_a_past_date_that_still_has_hours_is_not_bookable_or_counted(): void
    {
        $service = $this->service(60);
        $stylist = Stylist::factory()->create();
        $stylist->services()->attach($service->id);
        $stylist->dateHours()->create(['date' => Carbon::now('Asia/Kolkata')->subDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '13:00']);

        $row = collect($this->getJson('/api/stylists')->assertOk()->json('data'))->firstWhere('id', $stylist->id);

        $this->assertFalse($row['bookable']);
        $this->assertSame([], $row['date_hours']);
    }

    // --- Slot list ---------------------------------------------------------

    public function test_slots_follow_the_professionals_own_hours_and_the_service_length(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00'], ['14:00', '16:00']], $service);

        $response = $this->slots($service, $this->monday, $stylist)->assertOk()
            ->assertJsonPath('data.working', true);

        // no slot starts at 13:00 (their gap) and none runs past 16:00
        $this->assertSame(['10:00', '11:00', '12:00', '14:00', '15:00'], $this->availableStarts($response));
        $this->assertSame('11:00', $response->json('data.slots.0.end'));
    }

    public function test_a_day_off_has_no_slots_and_says_they_are_not_working(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00']], $service);

        $this->slots($service, $this->tuesday, $stylist)->assertOk()
            ->assertJsonPath('data.working', false)
            ->assertJsonPath('data.slots', []);
    }

    public function test_hours_are_clipped_to_the_studios_opening_hours(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['08:00', '12:00']], $service);

        // the studio opens at 10:00, so 08:00 and 09:00 are never offered
        $this->assertSame(['10:00', '11:00'], $this->availableStarts($this->slots($service, $this->monday, $stylist)));
    }

    public function test_a_confirmed_booking_blocks_its_time_plus_the_buffer_and_shows_as_booked(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00'], ['14:00', '16:00']], $service);

        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => $this->monday,
            'appointment_time' => '11:00',
        ]);

        $response = $this->slots($service, $this->monday, $stylist)->assertOk();

        // 11:00–12:00 is taken; the 10-minute buffer also rules out 10:00 (ends 11:00 > 10:50)
        // and 12:00; the afternoon is untouched.
        $this->assertSame(['14:00', '15:00'], $this->availableStarts($response));
        $this->assertSame(
            ['11:00'],
            collect($response->json('data.slots'))->where('status', 'booked')->pluck('start')->values()->all(),
        );
    }

    public function test_pending_and_other_professionals_bookings_do_not_block_slots(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '12:00']], $service);
        $other = $this->mondayStylist([['10:00', '12:00']], $service);

        Appointment::factory()->forService($service)->forStylist($stylist)->pending()->create([
            'appointment_date' => $this->monday, 'appointment_time' => '10:00',
        ]);
        Appointment::factory()->forService($service)->forStylist($other)->create([
            'status' => Appointment::STATUS_CONFIRMED, 'appointment_date' => $this->monday, 'appointment_time' => '11:00',
        ]);

        $this->assertSame(['10:00', '11:00'], $this->availableStarts($this->slots($service, $this->monday, $stylist)));
    }

    public function test_todays_slots_start_after_now_rounded_up_to_the_next_ten_minutes(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00']], $service);

        Carbon::setTestNow(Carbon::parse($this->monday.' 10:40', 'Asia/Kolkata'));

        $response = $this->slots($service, $this->monday, $stylist)->assertOk();

        $this->assertSame(['10:50', '11:50'], $this->availableStarts($response));
        $this->assertFalse($response->json('data.today_exhausted'));
    }

    public function test_today_is_exhausted_when_what_remains_cannot_fit_the_service(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00']], $service);

        Carbon::setTestNow(Carbon::parse($this->monday.' 12:30', 'Asia/Kolkata'));

        $this->slots($service, $this->monday, $stylist)->assertOk()
            ->assertJsonPath('data.slots', [])
            ->assertJsonPath('data.today_exhausted', true);
    }

    public function test_past_dates_have_no_slots(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00']], $service);

        $this->slots($service, '2020-01-06', $stylist)->assertOk()->assertJsonPath('data.slots', []);
    }

    public function test_any_professional_combines_everyone_who_offers_the_service(): void
    {
        $service = $this->service(60);
        $elsewhere = $this->service(60);
        $this->mondayStylist([['10:00', '12:00']], $service);
        $this->mondayStylist([['14:00', '16:00']], $service);
        $this->mondayStylist([['12:00', '13:00']], $elsewhere); // does not offer $service

        $this->assertSame(
            ['10:00', '11:00', '14:00', '15:00'],
            $this->availableStarts($this->slots($service, $this->monday)),
        );
    }

    public function test_any_professional_keeps_a_time_open_while_at_least_one_person_is_free(): void
    {
        $service = $this->service(60);
        $busy = $this->mondayStylist([['10:00', '11:00']], $service);
        $this->mondayStylist([['10:00', '11:00']], $service);

        Appointment::factory()->forService($service)->forStylist($busy)->create([
            'status' => Appointment::STATUS_CONFIRMED, 'appointment_date' => $this->monday, 'appointment_time' => '10:00',
        ]);

        $this->assertSame(['10:00'], $this->availableStarts($this->slots($service, $this->monday)));
    }

    public function test_a_professional_who_does_not_offer_the_service_is_refused(): void
    {
        $service = $this->service(60);
        $other = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00']], $other);

        $this->slots($service, $this->monday, $stylist)
            ->assertStatus(422)->assertJsonValidationErrors('stylist_id');
    }

    public function test_the_slot_request_is_validated(): void
    {
        $this->getJson('/api/booking/slots')->assertStatus(422)->assertJsonValidationErrors(['service_id', 'date']);
        $this->getJson('/api/booking/slots?service_id=999999&date=2026-13-45')
            ->assertStatus(422)->assertJsonValidationErrors(['service_id', 'date']);
    }

    // --- Booking through the API follows the same rules ---------------------

    public function test_booking_inside_the_professionals_hours_is_accepted(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00']], $service);

        $this->book($service, $this->monday, '10:30', $stylist)
            ->assertCreated()
            ->assertJsonPath('data.stylist_id', $stylist->id);
    }

    public function test_booking_outside_their_hours_but_inside_the_studios_is_refused(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00']], $service);

        $this->book($service, $this->monday, '15:00', $stylist)
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time')
            ->assertJsonFragment(['appointment_time' => ["{$stylist->name} isn't working at that time. Please choose another slot."]]);
    }

    public function test_booking_that_runs_past_the_end_of_their_range_is_refused(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00']], $service);

        $this->book($service, $this->monday, '12:30', $stylist)
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_booking_on_their_day_off_is_refused(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00']], $service);

        $this->book($service, $this->tuesday, '10:30', $stylist)
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_booking_a_service_the_professional_does_not_offer_is_refused(): void
    {
        $service = $this->service(60);
        $other = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00']], $other);

        $this->book($service, $this->monday, '10:30', $stylist)
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time')
            ->assertJsonFragment(['appointment_time' => ["{$stylist->name} doesn't offer this service. Please choose another professional or service."]]);
    }

    public function test_any_professional_assigns_someone_who_is_working_at_that_time(): void
    {
        $service = $this->service(60);
        $morning = $this->mondayStylist([['10:00', '13:00']], $service);
        $afternoon = $this->mondayStylist([['14:00', '18:00']], $service);

        $this->book($service, $this->monday, '11:00')->assertCreated()->assertJsonPath('data.stylist_id', $morning->id);
        $this->book($service, $this->monday, '15:00')->assertCreated()->assertJsonPath('data.stylist_id', $afternoon->id);
    }

    public function test_any_professional_is_refused_when_nobody_is_working(): void
    {
        $service = $this->service(60);
        $this->mondayStylist([['10:00', '13:00']], $service);

        $this->book($service, $this->monday, '16:00')
            ->assertStatus(422)->assertJsonFragment(['appointment_time' => ['No professional is available at that time. Please choose another slot.']]);
    }

    public function test_any_professional_is_refused_when_nobody_offers_the_service(): void
    {
        $service = $this->service(60);
        $other = $this->service(60);
        $this->mondayStylist([['10:00', '13:00']], $other);

        $this->book($service, $this->monday, '10:30')
            ->assertStatus(422)->assertJsonFragment(['appointment_time' => ['No professional currently offers this service. Please contact the studio.']]);
    }

    public function test_an_inactive_professional_is_never_assigned(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['10:00', '13:00']], $service);
        $stylist->update(['status' => false]);

        $this->assertSame([], $this->availableStarts($this->slots($service, $this->monday)));
        $this->book($service, $this->monday, '10:30')->assertStatus(422);
    }

    public function test_the_studios_own_hours_still_apply_on_top_of_a_professionals_hours(): void
    {
        $service = $this->service(60);
        $stylist = $this->mondayStylist([['08:00', '12:00']], $service);

        // 09:00 is inside their range but before the studio opens
        $this->book($service, $this->monday, '09:00', $stylist)
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }
}
