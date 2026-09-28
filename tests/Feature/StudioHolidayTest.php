<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SiteSetting;
use App\Models\StudioHoliday;
use App\Models\Stylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

/**
 * Studio-wide closed days (Admin → Studio → Holidays): one entry closes the
 * whole studio for every professional on that date, whatever their own hours
 * say. See BookingAvailability::windows() for the precedence.
 */
class StudioHolidayTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    private string $monday;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        SiteSetting::query()->updateOrCreate(['key' => 'shop_opens_at'], ['value' => '10:00', 'type' => 'time', 'group' => 'shop_hours']);
        SiteSetting::query()->updateOrCreate(['key' => 'shop_closes_at'], ['value' => '19:30', 'type' => 'time', 'group' => 'shop_hours']);
        Cache::flush();

        $this->monday = Carbon::now('Asia/Kolkata')->next(Carbon::MONDAY)->toDateString();
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

    public function test_admin_can_add_list_and_remove_a_holiday(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/studio-holidays', ['date' => $this->monday, 'name' => 'Diwali'])
            ->assertCreated()
            ->assertJsonPath('data.date', $this->monday)
            ->assertJsonPath('data.name', 'Diwali');

        $this->getJson('/api/admin/studio-holidays')->assertOk()->assertJsonPath('data.0.name', 'Diwali');

        // Same date twice is rejected.
        $this->postJson('/api/admin/studio-holidays', ['date' => $this->monday, 'name' => 'Again'])->assertUnprocessable();

        $holiday = StudioHoliday::query()->first();
        $this->putJson("/api/admin/studio-holidays/{$holiday->id}", ['name' => 'Deepavali'])->assertOk()->assertJsonPath('data.name', 'Deepavali');

        $this->deleteJson("/api/admin/studio-holidays/{$holiday->id}")->assertNoContent();
        $this->assertSame(0, StudioHoliday::query()->count());
    }

    public function test_holiday_closes_every_stylist_even_with_weekly_hours(): void
    {
        $service = $this->service();
        $stylist = $this->stylistOffering($service);
        $weekday = Carbon::parse($this->monday, 'Asia/Kolkata')->dayOfWeek;

        $this->actingAsToken($this->superadmin());
        $this->putJson("/api/admin/stylists/{$stylist->id}/weekly-hours", [
            'days' => [['weekday' => $weekday, 'ranges' => [['start' => '10:00', 'end' => '19:00']]]],
        ])->assertOk();
        $this->postJson('/api/admin/studio-holidays', ['date' => $this->monday, 'name' => 'Diwali'])->assertCreated();

        // Public slots: nothing bookable, not a working day.
        $slots = $this->getJson('/api/booking/slots?'.http_build_query([
            'service_id' => $service->id, 'date' => $this->monday, 'stylist_id' => $stylist->id,
        ]))->assertOk()->json('data');
        $this->assertFalse($slots['working']);
        $this->assertSame([], $slots['slots']);

        // Public holiday list exposes it for the booking calendar.
        $this->getJson('/api/studio-holidays')->assertOk()->assertJsonPath('data.0.date', $this->monday);

        // The stylist setup payload carries it so the admin calendar can show it.
        $this->actingAsToken($this->superadmin());
        $this->getJson("/api/admin/stylists/{$stylist->id}/setup")->assertOk()->assertJsonPath('data.studio_holidays.0.name', 'Diwali');
    }

    public function test_booking_on_a_holiday_is_rejected(): void
    {
        $service = $this->service();
        $stylist = $this->stylistOffering($service);
        $weekday = Carbon::parse($this->monday, 'Asia/Kolkata')->dayOfWeek;

        $this->actingAsToken($this->superadmin());
        $this->putJson("/api/admin/stylists/{$stylist->id}/weekly-hours", [
            'days' => [['weekday' => $weekday, 'ranges' => [['start' => '10:00', 'end' => '19:00']]]],
        ])->assertOk();
        $this->postJson('/api/admin/studio-holidays', ['date' => $this->monday, 'name' => 'Diwali'])->assertCreated();

        $this->actingAsToken($this->customer());
        $this->postJson('/api/appointments', [
            'customer_name' => 'Priya R', 'phone' => '+91 9790431212', 'gender' => 'female',
            'category_id' => $service->service_category_id, 'service_id' => $service->id,
            'stylist_id' => $stylist->id, 'appointment_date' => $this->monday, 'appointment_time' => '11:00',
        ])->assertUnprocessable();
    }
}
