<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SiteSetting;
use App\Models\Stylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAdmins;
use Tests\Concerns\CreatesBookableStylists;
use Tests\TestCase;

/**
 * The admin "Services & hours" setup for a professional: which services they
 * offer (which also fixes their genders and categories) and their weekly
 * working hours.
 */
class StylistSetupTest extends TestCase
{
    use CreatesAdmins, CreatesBookableStylists, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->setShopHours('10:00', '19:30');
    }

    private function setShopHours(string $open, string $close): void
    {
        SiteSetting::query()->updateOrCreate(['key' => 'shop_opens_at'], ['value' => $open, 'type' => 'time', 'group' => 'shop_hours']);
        SiteSetting::query()->updateOrCreate(['key' => 'shop_closes_at'], ['value' => $close, 'type' => 'time', 'group' => 'shop_hours']);
        Cache::flush();
    }

    private function service(string $gender = 'female'): Service
    {
        $category = $gender === 'male'
            ? ServiceCategory::factory()->male()->create()
            : ServiceCategory::factory()->female()->create();

        return Service::factory()->forCategory($category)->create(['duration_minutes' => 60]);
    }

    private function days(array $byDay): array
    {
        $days = [];
        foreach ($byDay as $day => $ranges) {
            $days[] = [
                'day_of_week' => $day,
                'ranges' => array_map(fn ($r) => ['start' => $r[0], 'end' => $r[1]], $ranges),
            ];
        }

        return $days;
    }

    // --- Reading ------------------------------------------------------------

    public function test_setup_returns_services_hours_shop_hours_and_the_catalogue(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $men = $this->service('male');
        $women = $this->service('female');
        $stylist->services()->attach($men->id);
        $this->giveWorkHours($stylist, [['10:00', '13:00']]);

        $this->getJson("/api/admin/stylists/{$stylist->id}/setup")
            ->assertOk()
            ->assertJsonPath('data.stylist.id', $stylist->id)
            ->assertJsonPath('data.service_ids', [$men->id])
            ->assertJsonPath('data.work_hours.1.0', ['start' => '10:00', 'end' => '13:00'])
            ->assertJsonPath('data.shop_hours', ['opens' => '10:00', 'closes' => '19:30'])
            // the whole catalogue is offered to pick from, with each category's gender
            ->assertJsonFragment(['id' => $men->id, 'name' => $men->name])
            ->assertJsonFragment(['id' => $women->id, 'name' => $women->name])
            ->assertJsonFragment(['gender' => 'male'])
            ->assertJsonFragment(['gender' => 'female']);
    }

    public function test_a_view_only_role_can_read_but_not_change_the_setup(): void
    {
        $this->actingAsToken($this->userWith(['stylists.view']));
        $stylist = Stylist::factory()->create();

        $this->getJson("/api/admin/stylists/{$stylist->id}/setup")->assertOk();
        $this->putJson("/api/admin/stylists/{$stylist->id}/services", ['service_ids' => []])->assertForbidden();
        $this->putJson("/api/admin/stylists/{$stylist->id}/work-hours", ['days' => []])->assertForbidden();
    }

    public function test_guests_cannot_reach_the_setup(): void
    {
        $stylist = Stylist::factory()->create();

        $this->getJson("/api/admin/stylists/{$stylist->id}/setup")->assertUnauthorized();
        $this->putJson("/api/admin/stylists/{$stylist->id}/services", ['service_ids' => []])->assertUnauthorized();
    }

    // --- Services -----------------------------------------------------------

    public function test_admin_can_replace_the_services_a_professional_offers(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        [$a, $b, $c] = [$this->service('male'), $this->service('male'), $this->service('female')];

        $this->putJson("/api/admin/stylists/{$stylist->id}/services", ['service_ids' => [$a->id, $b->id, $c->id]])
            ->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $stylist->services()->pluck('services.id')->all());

        // narrowing the list removes the rest
        $this->putJson("/api/admin/stylists/{$stylist->id}/services", ['service_ids' => [$c->id]])
            ->assertOk()
            ->assertJsonPath('data.service_ids', [$c->id]);
        $this->assertSame([$c->id], $stylist->services()->pluck('services.id')->all());

        // and an empty list clears it
        $this->putJson("/api/admin/stylists/{$stylist->id}/services", ['service_ids' => []])->assertOk();
        $this->assertSame(0, $stylist->services()->count());
    }

    public function test_service_ids_must_exist_and_the_field_is_required(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();

        $this->putJson("/api/admin/stylists/{$stylist->id}/services", ['service_ids' => [999999]])
            ->assertStatus(422)->assertJsonValidationErrors('service_ids.0');

        $this->putJson("/api/admin/stylists/{$stylist->id}/services", [])
            ->assertStatus(422)->assertJsonValidationErrors('service_ids');
    }

    // --- Working hours ------------------------------------------------------

    public function test_admin_can_set_the_weekly_hours_and_missing_days_become_days_off(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $this->giveWorkHours($stylist); // starts working every day

        $this->putJson("/api/admin/stylists/{$stylist->id}/work-hours", [
            'days' => $this->days([
                1 => [['10:00', '13:00'], ['14:00', '18:00']],
                2 => [['11:00', '15:00']],
                0 => [], // explicit day off
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('data.work_hours.1', [
                ['start' => '10:00', 'end' => '13:00'],
                ['start' => '14:00', 'end' => '18:00'],
            ])
            ->assertJsonPath('data.work_hours.2', [['start' => '11:00', 'end' => '15:00']])
            ->assertJsonPath('data.work_hours.0', [])
            // Wednesday was not sent, so it is a day off now
            ->assertJsonPath('data.work_hours.3', []);

        $this->assertSame(3, $stylist->workHours()->count());
    }

    #[DataProvider('invalidHours')]
    public function test_invalid_working_hours_are_rejected(array $byDay, string $errorKey): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();

        $this->putJson("/api/admin/stylists/{$stylist->id}/work-hours", ['days' => $this->days($byDay)])
            ->assertStatus(422)->assertJsonValidationErrors($errorKey);
    }

    public static function invalidHours(): array
    {
        return [
            'end before start' => [[1 => [['15:00', '12:00']]], 'days.0.ranges.0.end'],
            'zero length' => [[1 => [['12:00', '12:00']]], 'days.0.ranges.0.end'],
            'overlapping ranges' => [[1 => [['10:00', '14:00'], ['13:00', '17:00']]], 'days.0.ranges.1.start'],
            'starts before the studio opens' => [[1 => [['09:00', '13:00']]], 'days.0.ranges.0.start'],
            'ends after the studio closes' => [[1 => [['14:00', '20:30']]], 'days.0.ranges.0.start'],
            'too many ranges in a day' => [[1 => [['10:00', '11:00'], ['11:30', '12:00'], ['12:30', '13:00'], ['14:00', '15:00'], ['16:00', '17:00']]], 'days.0.ranges'],
        ];
    }

    public function test_a_day_can_only_be_listed_once_and_times_must_be_hh_mm(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();

        $this->putJson("/api/admin/stylists/{$stylist->id}/work-hours", [
            'days' => [...$this->days([1 => [['10:00', '12:00']]]), ['day_of_week' => 1, 'ranges' => []]],
        ])->assertStatus(422)->assertJsonValidationErrors('days.1.day_of_week');

        $this->putJson("/api/admin/stylists/{$stylist->id}/work-hours", [
            'days' => $this->days([1 => [['10am', '12pm']]]),
        ])->assertStatus(422)->assertJsonValidationErrors('days.0.ranges.0.start');
    }

    public function test_hours_are_not_range_checked_against_the_studio_when_none_are_set(): void
    {
        $this->actingAsToken($this->superadmin());
        SiteSetting::query()->whereIn('key', ['shop_opens_at', 'shop_closes_at'])->delete();
        Cache::flush();
        $stylist = Stylist::factory()->create();

        $this->putJson("/api/admin/stylists/{$stylist->id}/work-hours", [
            'days' => $this->days([1 => [['06:00', '23:00']]]),
        ])->assertOk();
    }

    // --- New professionals & the list --------------------------------------

    public function test_a_new_professional_starts_with_the_default_weekly_hours_and_no_services(): void
    {
        $this->actingAsToken($this->superadmin());

        $response = $this->postJson('/api/admin/stylists', ['name' => 'New Person'])->assertCreated();

        // 7 days x (10:00–13:00 and 14:00–19:30 around the studio break)
        $response->assertJsonPath('data.work_hours_count', 14)
            ->assertJsonPath('data.services_count', 0);

        $stylist = Stylist::findOrFail($response->json('data.id'));
        $this->assertSame(['10:00', '13:00'], [$stylist->workHours()->where('day_of_week', 3)->orderBy('start_time')->first()->start(), $stylist->workHours()->where('day_of_week', 3)->orderBy('start_time')->first()->end()]);
    }

    public function test_the_admin_list_reports_how_set_up_each_professional_is(): void
    {
        $this->actingAsToken($this->superadmin());
        $ready = $this->bookableStylistFor($this->service());
        $bare = Stylist::factory()->create();

        $rows = collect($this->getJson('/api/admin/stylists?per_page=100')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(1, $rows[$ready->id]['services_count']);
        $this->assertGreaterThan(0, $rows[$ready->id]['work_hours_count']);
        $this->assertSame(0, $rows[$bare->id]['services_count']);
        $this->assertSame(0, $rows[$bare->id]['work_hours_count']);
    }
}
