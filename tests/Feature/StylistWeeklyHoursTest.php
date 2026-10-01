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
use Tests\TestCase;

/**
 * A professional's standing weekly schedule ("every Monday 10–6", no end
 * date) — the default a calendar date falls back to when it has no hours of
 * its own and isn't explicitly closed. Specific calendar dates
 * (StylistCalendarHoursTest) and explicit closures still win over it for that
 * one date. See BookingAvailability::windows() for the exact precedence.
 */
class StylistWeeklyHoursTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    /** Monday, Tuesday and Sunday of the coming week, plus the Monday four weeks after that. */
    private string $monday;

    private string $tuesday;

    private string $sunday;

    private string $mondayInAMonth;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->setShopHours('10:00', '19:30');

        $next = Carbon::now('Asia/Kolkata')->next(Carbon::MONDAY);
        $this->monday = $next->toDateString();
        $this->tuesday = $next->copy()->addDay()->toDateString();
        $this->sunday = $next->copy()->addDays(6)->toDateString();
        $this->mondayInAMonth = $next->copy()->addWeeks(4)->toDateString();
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

    private function stylistOffering(Service $service): Stylist
    {
        $stylist = Stylist::factory()->create();
        $stylist->services()->attach($service->id);

        return $stylist;
    }

    private function setWeekly(Stylist $stylist, array $days): TestResponse
    {
        return $this->putJson("/api/admin/stylists/{$stylist->id}/weekly-hours", ['days' => $days]);
    }

    private function weekday(int $weekday, array $ranges): array
    {
        return ['weekday' => $weekday, 'ranges' => array_map(fn ($r) => ['start' => $r[0], 'end' => $r[1]], $ranges)];
    }

    private function setDates(Stylist $stylist, array $days): TestResponse
    {
        return $this->putJson("/api/admin/stylists/{$stylist->id}/date-hours", ['days' => $days]);
    }

    private function custom(string $date, array $ranges): array
    {
        return ['date' => $date, 'mode' => 'custom', 'ranges' => array_map(fn ($r) => ['start' => $r[0], 'end' => $r[1]], $ranges)];
    }

    private function closed(string $date): array
    {
        return ['date' => $date, 'mode' => 'closed'];
    }

    private function clear(string $date): array
    {
        return ['date' => $date, 'mode' => 'clear'];
    }

    private function slotStarts(Service $service, string $date, ?Stylist $stylist = null): array
    {
        $response = $this->getJson('/api/booking/slots?'.http_build_query(array_filter([
            'service_id' => $service->id, 'date' => $date, 'stylist_id' => $stylist?->id,
        ])))->assertOk();

        return collect($response->json('data.slots'))->where('status', 'available')->pluck('start')->values()->all();
    }

    private function book(Service $service, string $date, string $time, ?Stylist $stylist = null): TestResponse
    {
        return $this->postJson('/api/appointments', array_filter([
            'customer_name' => 'Priya R', 'phone' => '+91 9790431212', 'gender' => 'female',
            'category_id' => $service->service_category_id, 'service_id' => $service->id,
            'stylist_id' => $stylist?->id, 'appointment_date' => $date, 'appointment_time' => $time,
        ]));
    }

    // --- Admin: setting the weekly schedule ------------------------------------

    public function test_a_weekday_can_be_given_standing_hours_changed_and_cleared(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = $this->stylistOffering($this->service());

        $empty = $this->getJson("/api/admin/stylists/{$stylist->id}/setup")->assertOk();
        $this->assertStringContainsString('"weekly_hours":{}', $empty->getContent());

        $this->setWeekly($stylist, [$this->weekday(1, [['10:00', '13:00'], ['14:00', '18:00']])])
            ->assertOk()
            ->assertJsonPath('data.weekly_hours.1', [
                ['start' => '10:00', 'end' => '13:00'],
                ['start' => '14:00', 'end' => '18:00'],
            ]);
        $this->assertSame(2, $stylist->weeklyHours()->count());

        // setting it again replaces the ranges, it doesn't add to them
        $this->setWeekly($stylist, [$this->weekday(1, [['11:00', '15:00']])])
            ->assertOk()->assertJsonPath('data.weekly_hours.1', [['start' => '11:00', 'end' => '15:00']]);
        $this->assertSame(1, $stylist->weeklyHours()->count());

        // an empty range list clears that weekday — it's simply not part of the pattern
        $this->setWeekly($stylist, [$this->weekday(1, [])])->assertOk();
        $this->assertSame(0, $stylist->weeklyHours()->count());
        $this->assertObjectNotHasProperty('1', (object) $this->getJson("/api/admin/stylists/{$stylist->id}/setup")->json('data.weekly_hours'));
    }

    public function test_several_weekdays_can_be_set_at_once_and_only_the_named_weekdays_change(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = $this->stylistOffering($this->service());
        $this->setWeekly($stylist, [$this->weekday(0, [['11:00', '15:00']])])->assertOk(); // Sunday

        // Mon–Fri in one request, as the admin "Weekday" quick-setup tab does
        $this->setWeekly($stylist, [
            $this->weekday(1, [['10:00', '18:00']]),
            $this->weekday(2, [['10:00', '18:00']]),
            $this->weekday(3, [['10:00', '18:00']]),
            $this->weekday(4, [['10:00', '18:00']]),
            $this->weekday(5, [['10:00', '18:00']]),
        ])->assertOk();

        $this->assertSame(6, $stylist->weeklyHours()->count());
        $this->assertSame('11:00', $stylist->weeklyHours()->where('weekday', 0)->first()->start()); // Sunday untouched
    }

    /** @param  array<int, array<string, mixed>>  $days */
    #[DataProvider('invalidWeeklyDays')]
    public function test_invalid_weekly_changes_are_rejected(array $days, string $errorKey): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();

        $this->setWeekly($stylist, $days)->assertStatus(422)->assertJsonValidationErrors($errorKey);
    }

    public static function invalidWeeklyDays(): array
    {
        $ok = ['start' => '10:00', 'end' => '12:00'];

        return [
            'no days' => [[], 'days'],
            'weekday too high' => [[['weekday' => 7, 'ranges' => [$ok]]], 'days.0.weekday'],
            'weekday negative' => [[['weekday' => -1, 'ranges' => [$ok]]], 'days.0.weekday'],
            'same weekday twice' => [[['weekday' => 1, 'ranges' => [$ok]], ['weekday' => 1, 'ranges' => [$ok]]], 'days.1.weekday'],
            'times not in HH:MM' => [[['weekday' => 1, 'ranges' => [['start' => '10am', 'end' => '12pm']]]], 'days.0.ranges.0.start'],
            'end before start' => [[['weekday' => 1, 'ranges' => [['start' => '15:00', 'end' => '12:00']]]], 'days.0.ranges.0.end'],
            'overlapping' => [[['weekday' => 1, 'ranges' => [['start' => '10:00', 'end' => '14:00'], ['start' => '13:00', 'end' => '17:00']]]], 'days.0.ranges.1.start'],
            'before the studio opens' => [[['weekday' => 1, 'ranges' => [['start' => '08:00', 'end' => '12:00']]]], 'days.0.ranges.0.start'],
            'after the studio closes' => [[['weekday' => 1, 'ranges' => [['start' => '14:00', 'end' => '20:30']]]], 'days.0.ranges.0.start'],
            'too many ranges (13)' => [[['weekday' => 1, 'ranges' => array_fill(0, 13, $ok)]], 'days.0.ranges'],
        ];
    }

    public function test_only_people_who_can_manage_stylists_may_change_the_weekly_schedule(): void
    {
        $stylist = Stylist::factory()->create();

        $this->setWeekly($stylist, [$this->weekday(1, [['10:00', '12:00']])])->assertUnauthorized();

        $this->actingAsToken($this->userWith(['stylists.view']));
        $this->setWeekly($stylist, [$this->weekday(1, [['10:00', '12:00']])])->assertForbidden();
        // …but can read it
        $this->getJson("/api/admin/stylists/{$stylist->id}/setup")->assertOk();
    }

    public function test_appointments_left_outside_the_new_weekly_hours_are_reported_not_cancelled(): void
    {
        $this->actingAsToken($this->superadmin());
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);
        $this->setWeekly($stylist, [$this->weekday(1, [['10:00', '13:00']])])->assertOk();

        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED, 'appointment_date' => $this->monday, 'appointment_time' => '11:00',
        ]);
        $cancelled = Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CANCELLED, 'appointment_date' => $this->monday, 'appointment_time' => '10:00',
        ]);

        // narrower hours strand the live appointment
        $this->setWeekly($stylist, [$this->weekday(1, [['10:00', '10:30']])])
            ->assertOk()
            ->assertJsonPath('warnings', [['date' => $this->monday, 'count' => 1]]);

        $this->assertSame(Appointment::STATUS_CANCELLED, $cancelled->refresh()->status);
        $this->assertSame(2, Appointment::count());
    }

    // --- Precedence: specific date / closure / weekly schedule -----------------

    public function test_a_stylist_with_only_a_weekly_schedule_is_bookable_on_matching_weekdays(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);
        $this->actingAsToken($this->superadmin());
        $this->setWeekly($stylist, [$this->weekday(1, [['10:00', '13:00']])])->assertOk(); // Monday only

        $this->assertSame(['10:00', '11:00', '12:00'], $this->slotStarts($service, $this->monday, $stylist));
        $this->assertSame([], $this->slotStarts($service, $this->tuesday, $stylist));
        $this->getJson("/api/booking/slots?service_id={$service->id}&date={$this->tuesday}&stylist_id={$stylist->id}")
            ->assertJsonPath('data.working', false);

        $this->book($service, $this->monday, '10:30', $stylist)->assertCreated();
        $this->book($service, $this->tuesday, '10:30', $stylist)->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_the_weekly_schedule_applies_indefinitely_not_just_for_a_fixed_number_of_days(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);
        $this->actingAsToken($this->superadmin());
        $this->setWeekly($stylist, [$this->weekday(1, [['10:00', '13:00']])])->assertOk();

        // a Monday four weeks out — well past the old fixed calendar-fill window
        $this->assertSame(['10:00', '11:00', '12:00'], $this->slotStarts($service, $this->mondayInAMonth, $stylist));
        $this->book($service, $this->mondayInAMonth, '10:30', $stylist)->assertCreated();
    }

    public function test_a_specific_dates_hours_override_the_weekly_schedule_for_that_date_only(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);
        $this->actingAsToken($this->superadmin());
        $this->setWeekly($stylist, [$this->weekday(1, [['10:00', '18:00']])])->assertOk();
        $this->setDates($stylist, [$this->custom($this->monday, [['14:00', '16:00']])])->assertOk();

        $this->assertSame(['14:00', '15:00'], $this->slotStarts($service, $this->monday, $stylist));
        // the following Monday is untouched — still the full weekly hours
        $this->assertSame(['10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00'], $this->slotStarts($service, $this->mondayInAMonth, $stylist));
    }

    public function test_marking_one_date_closed_overrides_an_otherwise_open_weekly_day(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);
        $this->actingAsToken($this->superadmin());
        $this->setWeekly($stylist, [$this->weekday(1, [['10:00', '13:00']])])->assertOk();
        $this->setDates($stylist, [$this->closed($this->monday)])->assertOk();

        $this->assertSame([], $this->slotStarts($service, $this->monday, $stylist));
        $this->book($service, $this->monday, '10:30', $stylist)->assertStatus(422)->assertJsonValidationErrors('appointment_time');
        // a different Monday is unaffected — the closure is just for that one date
        $this->assertSame(['10:00', '11:00', '12:00'], $this->slotStarts($service, $this->mondayInAMonth, $stylist));

        // book() above signed in as the customer — back to admin to read the setup page
        $this->actingAsToken($this->superadmin());
        $this->getJson("/api/admin/stylists/{$stylist->id}/setup")
            ->assertOk()
            ->assertJsonPath('data.date_closures', [$this->monday]);
    }

    public function test_clearing_a_custom_or_closed_override_reverts_the_date_to_the_weekly_schedule(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);
        $this->actingAsToken($this->superadmin());
        $this->setWeekly($stylist, [$this->weekday(1, [['10:00', '13:00']])])->assertOk();

        $this->setDates($stylist, [$this->closed($this->monday)])->assertOk();
        $this->assertSame([], $this->slotStarts($service, $this->monday, $stylist));

        $this->setDates($stylist, [$this->clear($this->monday)])->assertOk();
        $this->assertSame(['10:00', '11:00', '12:00'], $this->slotStarts($service, $this->monday, $stylist));
        $this->assertSame(0, $stylist->dateClosures()->count());

        // same, coming from a custom override instead
        $this->setDates($stylist, [$this->custom($this->monday, [['14:00', '15:00']])])->assertOk();
        $this->assertSame(['14:00'], $this->slotStarts($service, $this->monday, $stylist));
        $this->setDates($stylist, [$this->clear($this->monday)])->assertOk();
        $this->assertSame(['10:00', '11:00', '12:00'], $this->slotStarts($service, $this->monday, $stylist));

        // giving a date custom hours also lifts an existing closure for it
        $this->setDates($stylist, [$this->closed($this->monday)])->assertOk();
        $this->setDates($stylist, [$this->custom($this->monday, [['14:00', '15:00']])])->assertOk();
        $this->assertSame(0, $stylist->dateClosures()->count());
        $this->assertSame(['14:00'], $this->slotStarts($service, $this->monday, $stylist));
    }

    public function test_a_weekly_only_stylist_can_be_assigned_when_no_professional_is_requested(): void
    {
        $service = $this->service(60);
        $stylist = $this->stylistOffering($service);
        $this->actingAsToken($this->superadmin());
        $this->setWeekly($stylist, [$this->weekday(1, [['10:00', '13:00']])])->assertOk();

        $this->assertSame(['10:00', '11:00', '12:00'], $this->slotStarts($service, $this->monday));
        $this->book($service, $this->monday, '10:30')->assertCreated();
    }

    public function test_the_admin_and_public_stylist_lists_count_a_weekly_only_stylist_as_bookable(): void
    {
        $service = $this->service();
        $stylist = $this->stylistOffering($service);
        $this->actingAsToken($this->superadmin());
        $this->setWeekly($stylist, [$this->weekday(1, [['10:00', '13:00']])])->assertOk();

        $row = collect($this->getJson('/api/admin/stylists?per_page=100')->json('data'))->firstWhere('id', $stylist->id);
        $this->assertSame(0, $row['upcoming_days_count']); // no date OF ITS OWN — still bookable via the weekly schedule
        $this->assertSame([['start' => '10:00', 'end' => '13:00']], $row['weekly_hours']['1']);

        $public = collect($this->getJson('/api/stylists')->json('data'))->firstWhere('id', $stylist->id);
        $this->assertTrue($public['bookable']);
        $this->assertSame([['start' => '10:00', 'end' => '13:00']], $public['weekly_hours']['1']);
    }
}
