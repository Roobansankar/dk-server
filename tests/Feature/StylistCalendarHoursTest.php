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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAdmins;
use Tests\Concerns\CreatesBookableStylists;
use Tests\TestCase;

/**
 * The hours an admin gives a professional on the calendar are the ONLY thing
 * that makes them bookable: nothing is open by default, a date with no hours
 * is not available, and the booking rules honour exactly what was set.
 */
class StylistCalendarHoursTest extends TestCase
{
    use CreatesAdmins, CreatesBookableStylists, RefreshDatabase;

    private string $monday;

    private string $tuesday;

    private string $nextMonday;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->setShopHours('10:00', '19:30');

        $next = Carbon::now('Asia/Kolkata')->next(Carbon::MONDAY);
        $this->monday = $next->toDateString();
        $this->tuesday = $next->copy()->addDay()->toDateString();
        $this->nextMonday = $next->copy()->addWeek()->toDateString();
    }

    private function setShopHours(string $open, string $close): void
    {
        SiteSetting::query()->updateOrCreate(['key' => 'shop_opens_at'], ['value' => $open, 'type' => 'time', 'group' => 'shop_hours']);
        SiteSetting::query()->updateOrCreate(['key' => 'shop_closes_at'], ['value' => $close, 'type' => 'time', 'group' => 'shop_hours']);
        Cache::flush();
    }

    private function service(int $minutes = 60): Service
    {
        $category = ServiceCategory::factory()->female()->create();

        return Service::factory()->forCategory($category)->create(['duration_minutes' => $minutes, 'price' => 1000, 'advance_percentage' => 0]);
    }

    /** A professional who offers $service and has NO hours set anywhere. */
    private function stylistOffering(Service $service): Stylist
    {
        $stylist = Stylist::factory()->create();
        $stylist->services()->attach($service->id);

        return $stylist;
    }

    private function setDates(Stylist $stylist, array $days): TestResponse
    {
        return $this->putJson("/api/admin/stylists/{$stylist->id}/date-hours", ['days' => $days]);
    }

    private function clear(string $date): array
    {
        return ['date' => $date, 'mode' => 'clear'];
    }

    private function custom(string $date, array $ranges): array
    {
        return [
            'date' => $date,
            'mode' => 'custom',
            'ranges' => array_map(fn ($r) => ['start' => $r[0], 'end' => $r[1]], $ranges),
        ];
    }

    private function slotStarts(Service $service, string $date, ?Stylist $stylist = null): array
    {
        $response = $this->getJson('/api/booking/slots?'.http_build_query(array_filter([
            'service_id' => $service->id, 'date' => $date, 'stylist_id' => $stylist?->id,
        ])))->assertOk();

        return collect($response->json('data.slots'))->where('status', 'available')->pluck('start')->values()->all();
    }

    private function book(Service $service, string $date, string $time, Stylist $stylist): TestResponse
    {
        $this->actingAsToken($this->customer());

        return $this->postJson('/api/appointments', [
            'customer_name' => 'Priya R', 'phone' => '+91 9790431212', 'gender' => 'female',
            'category_id' => $service->service_category_id, 'service_id' => $service->id,
            'stylist_id' => $stylist->id, 'appointment_date' => $date, 'appointment_time' => $time,
        ]);
    }

    // --- Admin: setting dates ------------------------------------------------

    public function test_nothing_is_open_until_an_admin_sets_a_date(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);

        // no date is selected by default, on any day
        foreach ([$this->monday, $this->tuesday, $this->nextMonday] as $date) {
            $this->assertSame([], $this->slotStarts($service, $date, $stylist));
            $this->book($service, $date, '11:00', $stylist)->assertStatus(422)->assertJsonValidationErrors('appointment_time');
        }
        $this->assertSame(0, $stylist->dateHours()->count());
    }

    public function test_setup_lists_the_upcoming_calendar_dates_as_a_date_keyed_map(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = $this->stylistOffering($this->service());

        // nothing set yet → an empty JSON *object* (so the calendar can index it by date), not `[]`
        $empty = $this->getJson("/api/admin/stylists/{$stylist->id}/setup")->assertOk();
        $this->assertStringContainsString('"date_hours":{}', $empty->getContent());

        $this->setDates($stylist, [
            $this->custom($this->monday, [['10:00', '12:00'], ['14:00', '16:00']]),
            $this->custom($this->tuesday, [['11:00', '15:00']]),
        ])->assertOk();

        $this->getJson("/api/admin/stylists/{$stylist->id}/setup")
            ->assertOk()
            ->assertJsonPath("data.date_hours.{$this->monday}", [
                ['start' => '10:00', 'end' => '12:00'],
                ['start' => '14:00', 'end' => '16:00'],
            ])
            ->assertJsonPath("data.date_hours.{$this->tuesday}", [['start' => '11:00', 'end' => '15:00']]);
    }

    public function test_a_date_can_be_given_hours_changed_and_cleared(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = $this->stylistOffering($this->service());

        $this->setDates($stylist, [$this->custom($this->monday, [['10:00', '12:00'], ['14:00', '16:00']])])
            ->assertOk()
            ->assertJsonPath("data.date_hours.{$this->monday}", [
                ['start' => '10:00', 'end' => '12:00'],
                ['start' => '14:00', 'end' => '16:00'],
            ]);
        $this->assertSame(2, $stylist->dateHours()->count());

        // setting it again replaces the ranges, it doesn't add to them
        $this->setDates($stylist, [$this->custom($this->monday, [['11:00', '13:00']])])
            ->assertOk()->assertJsonPath("data.date_hours.{$this->monday}", [['start' => '11:00', 'end' => '13:00']]);
        $this->assertSame(1, $stylist->dateHours()->count());

        // clearing removes the date entirely — it is simply not available
        $this->setDates($stylist, [$this->clear($this->monday)])->assertOk();
        $this->assertSame(0, $stylist->dateHours()->count());
        $this->assertObjectNotHasProperty($this->monday, (object) $this->getJson("/api/admin/stylists/{$stylist->id}/setup")->json('data.date_hours'));
    }

    public function test_many_dates_can_be_set_at_once_and_only_the_named_dates_change(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = $this->stylistOffering($this->service());
        $this->setDates($stylist, [$this->custom($this->nextMonday, [['11:00', '14:00']])])->assertOk();

        $this->setDates($stylist, [
            $this->custom($this->monday, [['10:00', '12:00']]),
            $this->custom($this->tuesday, [['10:00', '12:00']]),
        ])->assertOk();

        $this->assertSame(3, $stylist->dateHours()->distinct()->count('date'));
        $this->assertSame('11:00', $stylist->dateHours()->whereDate('date', $this->nextMonday)->first()->start());
    }

    public function test_a_day_can_be_listed_hour_by_hour_up_to_twelve_ranges(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = $this->stylistOffering($this->service());

        // twelve back-to-back half-hour ranges, 10:00–16:00 (a day someone lists slot by slot)
        $time = fn (int $halfHours) => sprintf('%02d:%02d', 10 + intdiv($halfHours, 2), ($halfHours % 2) * 30);
        $ranges = array_map(fn (int $i) => [$time($i), $time($i + 1)], range(0, 11));

        $this->setDates($stylist, [$this->custom($this->monday, $ranges)])
            ->assertOk()
            ->assertJsonCount(12, "data.date_hours.{$this->monday}");

        $this->assertSame(12, $stylist->dateHours()->count());
    }

    public function test_the_admin_list_counts_upcoming_days_not_ranges(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = $this->stylistOffering($this->service());
        $this->setDates($stylist, [
            $this->custom($this->monday, [['10:00', '12:00'], ['14:00', '16:00']]), // two ranges, one day
            $this->custom($this->tuesday, [['10:00', '12:00']]),
        ])->assertOk();
        // a date that has already passed never counts
        $stylist->dateHours()->create(['date' => Carbon::now('Asia/Kolkata')->subDays(2)->toDateString(), 'start_time' => '10:00', 'end_time' => '12:00']);

        $row = collect($this->getJson('/api/admin/stylists?per_page=100')->json('data'))->firstWhere('id', $stylist->id);

        $this->assertSame(2, $row['upcoming_days_count']);
    }

    /** @param  array<int, array<string, mixed>>  $days */
    #[DataProvider('invalidDays')]
    public function test_invalid_calendar_changes_are_rejected(array $days, string $errorKey): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();

        // dates in the provider are relative offsets, resolved here
        $days = array_map(function ($day) {
            if (isset($day['date']) && is_int($day['date'])) {
                $day['date'] = Carbon::now('Asia/Kolkata')->addDays($day['date'])->toDateString();
            }

            return $day;
        }, $days);

        $this->setDates($stylist, $days)->assertStatus(422)->assertJsonValidationErrors($errorKey);
    }

    public static function invalidDays(): array
    {
        $ok = ['start' => '10:00', 'end' => '12:00'];

        return [
            'no days' => [[], 'days'],
            'a past date' => [[['date' => -1, 'mode' => 'clear']], 'days.0.date'],
            'too far ahead' => [[['date' => 500, 'mode' => 'clear']], 'days.0.date'],
            'bad mode' => [[['date' => 3, 'mode' => 'holiday']], 'days.0.mode'],
            'the retired weekly mode' => [[['date' => 3, 'mode' => 'regular']], 'days.0.mode'],
            'bad date format' => [[['date' => '05/10/2026', 'mode' => 'clear']], 'days.0.date'],
            'custom without ranges' => [[['date' => 3, 'mode' => 'custom', 'ranges' => []]], 'days.0.ranges'],
            'times not in HH:MM' => [[['date' => 3, 'mode' => 'custom', 'ranges' => [['start' => '10am', 'end' => '12pm']]]], 'days.0.ranges.0.start'],
            'end before start' => [[['date' => 3, 'mode' => 'custom', 'ranges' => [['start' => '15:00', 'end' => '12:00']]]], 'days.0.ranges.0.end'],
            'zero length' => [[['date' => 3, 'mode' => 'custom', 'ranges' => [['start' => '12:00', 'end' => '12:00']]]], 'days.0.ranges.0.end'],
            'overlapping' => [[['date' => 3, 'mode' => 'custom', 'ranges' => [['start' => '10:00', 'end' => '14:00'], ['start' => '13:00', 'end' => '17:00']]]], 'days.0.ranges.1.start'],
            'before the studio opens' => [[['date' => 3, 'mode' => 'custom', 'ranges' => [['start' => '08:00', 'end' => '12:00']]]], 'days.0.ranges.0.start'],
            'after the studio closes' => [[['date' => 3, 'mode' => 'custom', 'ranges' => [['start' => '14:00', 'end' => '20:30']]]], 'days.0.ranges.0.start'],
            'too many ranges (13)' => [[['date' => 3, 'mode' => 'custom', 'ranges' => array_fill(0, 13, $ok)]], 'days.0.ranges'],
            'same date twice' => [[['date' => 3, 'mode' => 'clear'], ['date' => 3, 'mode' => 'clear']], 'days.1.date'],
        ];
    }

    public function test_hours_are_not_range_checked_against_the_studio_when_none_are_set(): void
    {
        $this->actingAsToken($this->superadmin());
        SiteSetting::query()->whereIn('key', ['shop_opens_at', 'shop_closes_at'])->delete();
        Cache::flush();
        $stylist = Stylist::factory()->create();

        $this->setDates($stylist, [$this->custom($this->monday, [['06:00', '23:00']])])->assertOk();
    }

    public function test_today_can_still_be_changed(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();

        $this->setDates($stylist, [$this->custom(Carbon::now('Asia/Kolkata')->toDateString(), [['10:00', '12:00']])])->assertOk();
    }

    public function test_only_people_who_can_manage_stylists_may_change_the_calendar(): void
    {
        $stylist = Stylist::factory()->create();

        $this->setDates($stylist, [$this->clear($this->monday)])->assertUnauthorized();

        $this->actingAsToken($this->userWith(['stylists.view']));
        $this->setDates($stylist, [$this->clear($this->monday)])->assertForbidden();
        // …but can read it
        $this->getJson("/api/admin/stylists/{$stylist->id}/setup")->assertOk();
    }

    public function test_appointments_left_outside_the_new_hours_are_reported_not_cancelled(): void
    {
        $this->actingAsToken($this->superadmin());
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);
        $this->setDates($stylist, [$this->custom($this->monday, [['10:00', '13:00']])])->assertOk();

        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED, 'appointment_date' => $this->monday, 'appointment_time' => '11:00',
        ]);
        Appointment::factory()->forService($service)->forStylist($stylist)->pending()->create([
            'appointment_date' => $this->monday, 'appointment_time' => '12:00',
        ]);
        $cancelled = Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CANCELLED, 'appointment_date' => $this->monday, 'appointment_time' => '10:00',
        ]);

        // hours that still cover both → nothing to report
        $this->setDates($stylist, [$this->custom($this->monday, [['10:00', '13:00']])])
            ->assertOk()->assertJsonPath('warnings', []);

        // hours that still cover only one of them strand the other
        $this->setDates($stylist, [$this->custom($this->monday, [['10:00', '12:00']])])
            ->assertOk()
            ->assertJsonPath('warnings', [['date' => $this->monday, 'count' => 1]]);

        // clearing the whole date strands both live appointments (the cancelled one doesn't count)
        $this->setDates($stylist, [$this->clear($this->monday)])
            ->assertOk()
            ->assertJsonPath('warnings', [['date' => $this->monday, 'count' => 2]]);

        $this->assertSame(Appointment::STATUS_CANCELLED, $cancelled->refresh()->status);
        $this->assertSame(3, Appointment::count());
    }

    // --- Booking honours the calendar ----------------------------------------

    public function test_only_the_dates_that_were_set_have_slots_and_can_be_booked(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);

        $this->actingAsToken($this->superadmin());
        $this->setDates($stylist, [$this->custom($this->monday, [['10:00', '13:00']])])->assertOk();

        $this->assertSame(['10:00', '11:00', '12:00'], $this->slotStarts($service, $this->monday, $stylist));

        // the very next day, and the same weekday a week later, were never given hours
        foreach ([$this->tuesday, $this->nextMonday] as $date) {
            $this->assertSame([], $this->slotStarts($service, $date, $stylist));
            $this->getJson("/api/booking/slots?service_id={$service->id}&date={$date}&stylist_id={$stylist->id}")
                ->assertJsonPath('data.working', false);
            $this->book($service, $date, '10:30', $stylist)->assertStatus(422)->assertJsonValidationErrors('appointment_time');
        }

        $this->book($service, $this->monday, '10:30', $stylist)->assertCreated();
    }

    public function test_clearing_a_date_removes_its_slots_and_bookings(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);

        $this->actingAsToken($this->superadmin());
        $this->setDates($stylist, [$this->custom($this->monday, [['10:00', '13:00']])])->assertOk();
        $this->assertSame(['10:00', '11:00', '12:00'], $this->slotStarts($service, $this->monday, $stylist));

        $this->setDates($stylist, [$this->clear($this->monday)])->assertOk();

        $this->assertSame([], $this->slotStarts($service, $this->monday, $stylist));
        $this->book($service, $this->monday, '10:30', $stylist)->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_the_times_that_were_given_are_exactly_what_can_be_booked(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);

        $this->actingAsToken($this->superadmin());
        $this->setDates($stylist, [$this->custom($this->monday, [['14:00', '17:00']])])->assertOk();

        $this->assertSame(['14:00', '15:00', '16:00'], $this->slotStarts($service, $this->monday, $stylist));
        $this->book($service, $this->monday, '11:00', $stylist)->assertStatus(422); // mornings were not given
        $this->book($service, $this->monday, '16:30', $stylist)->assertStatus(422); // would run past 17:00
        $this->book($service, $this->monday, '14:30', $stylist)->assertCreated();
    }

    public function test_a_split_day_leaves_the_gap_between_its_ranges_unbookable(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);

        $this->actingAsToken($this->superadmin());
        $this->setDates($stylist, [$this->custom($this->monday, [['10:00', '12:00'], ['14:00', '16:00']])])->assertOk();

        $this->assertSame(['10:00', '11:00', '14:00', '15:00'], $this->slotStarts($service, $this->monday, $stylist));
    }

    public function test_the_studios_opening_hours_still_cap_custom_dates(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);
        $this->actingAsToken($this->superadmin());
        $this->setDates($stylist, [$this->custom($this->monday, [['10:00', '19:30']])])->assertOk();

        // the studio later shortens its day: the saved custom range is clipped, not trusted
        $this->setShopHours('11:00', '13:00');

        $this->assertSame(['11:00', '12:00'], $this->slotStarts($service, $this->monday, $stylist));
    }

    public function test_the_public_roster_exposes_upcoming_dates_for_the_booking_page(): void
    {
        $service = $this->service();
        $stylist = $this->stylistOffering($service);
        $this->actingAsToken($this->superadmin());
        $this->setDates($stylist, [
            $this->custom($this->monday, [['10:00', '12:00']]),
            $this->custom($this->tuesday, [['11:00', '15:00']]),
        ])->assertOk();

        $row = collect($this->getJson('/api/stylists')->assertOk()->json('data'))->firstWhere('id', $stylist->id);

        $this->assertSame([['start' => '10:00', 'end' => '12:00']], $row['date_hours'][$this->monday]);
        $this->assertSame([['start' => '11:00', 'end' => '15:00']], $row['date_hours'][$this->tuesday]);
        $this->assertArrayNotHasKey($this->nextMonday, $row['date_hours']);
    }

    public function test_someone_becomes_bookable_once_a_date_has_hours_and_stops_when_they_are_cleared(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service); // offers a service, no dates yet
        $this->actingAsToken($this->superadmin());

        $row = fn () => collect($this->getJson('/api/stylists')->json('data'))->firstWhere('id', $stylist->id);

        $this->assertFalse($row()['bookable']);

        $this->setDates($stylist, [$this->custom($this->monday, [['10:00', '12:00']])])->assertOk();
        $this->assertTrue($row()['bookable']);

        // "any professional" can find them too
        $this->assertSame(['10:00', '11:00'], $this->slotStarts($service, $this->monday));

        $this->setDates($stylist, [$this->clear($this->monday)])->assertOk();
        $this->assertFalse($row()['bookable']);
        $this->assertSame([], $this->slotStarts($service, $this->monday));
    }
}
