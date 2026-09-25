<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SiteSetting;
use App\Models\Stylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesAdmins;
use Tests\Concerns\CreatesBookableStylists;
use Tests\TestCase;

/**
 * The admin "Services & hours" setup for a professional: which services they
 * offer (which also fixes their genders and categories) and the calendar
 * dates they can be booked on. Nothing is set by default. (Setting and
 * validating the dates themselves is covered in StylistCalendarHoursTest.)
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

    private function inDays(int $days): string
    {
        return Carbon::now('Asia/Kolkata')->addDays($days)->toDateString();
    }

    // --- Reading ------------------------------------------------------------

    public function test_setup_returns_services_dates_shop_hours_and_the_catalogue(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $men = $this->service('male');
        $women = $this->service('female');
        $stylist->services()->attach($men->id);
        $date = $this->inDays(3);
        $stylist->dateHours()->create(['date' => $date, 'start_time' => '10:00', 'end_time' => '13:00']);

        $this->getJson("/api/admin/stylists/{$stylist->id}/setup")
            ->assertOk()
            ->assertJsonPath('data.stylist.id', $stylist->id)
            ->assertJsonPath('data.service_ids', [$men->id])
            ->assertJsonPath("data.date_hours.{$date}", [['start' => '10:00', 'end' => '13:00']])
            ->assertJsonPath('data.shop_hours', ['opens' => '10:00', 'closes' => '19:30'])
            // the whole catalogue is offered to pick from, with each category's gender
            ->assertJsonFragment(['id' => $men->id, 'name' => $men->name])
            ->assertJsonFragment(['id' => $women->id, 'name' => $women->name])
            ->assertJsonFragment(['gender' => 'male'])
            ->assertJsonFragment(['gender' => 'female']);
    }

    public function test_the_setup_has_no_weekly_pattern(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();

        $this->getJson("/api/admin/stylists/{$stylist->id}/setup")
            ->assertOk()
            ->assertJsonMissingPath('data.work_hours');

        // the weekly endpoint no longer exists
        $this->putJson("/api/admin/stylists/{$stylist->id}/work-hours", ['days' => []])->assertNotFound();
    }

    public function test_a_view_only_role_can_read_but_not_change_the_setup(): void
    {
        $this->actingAsToken($this->userWith(['stylists.view']));
        $stylist = Stylist::factory()->create();

        $this->getJson("/api/admin/stylists/{$stylist->id}/setup")->assertOk();
        $this->putJson("/api/admin/stylists/{$stylist->id}/services", ['service_ids' => []])->assertForbidden();
        $this->putJson("/api/admin/stylists/{$stylist->id}/date-hours", ['days' => []])->assertForbidden();
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

    // --- New professionals & the list --------------------------------------

    public function test_a_new_professional_starts_with_no_services_and_no_hours_at_all(): void
    {
        $this->actingAsToken($this->superadmin());

        $response = $this->postJson('/api/admin/stylists', ['name' => 'New Person'])->assertCreated();

        $response->assertJsonPath('data.services_count', 0);

        $stylist = Stylist::findOrFail($response->json('data.id'));
        $this->assertSame(0, $stylist->dateHours()->count(), 'no dates are pre-selected');

        $this->getJson("/api/admin/stylists/{$stylist->id}/setup")
            ->assertOk()
            ->assertJsonPath('data.date_hours', [])
            ->assertJsonPath('data.service_ids', []);
        $this->assertStringContainsString('"date_hours":{}', $this->getJson("/api/admin/stylists/{$stylist->id}/setup")->getContent());
    }

    public function test_the_admin_list_reports_how_set_up_each_professional_is(): void
    {
        $this->actingAsToken($this->superadmin());
        $ready = $this->bookableStylistFor($this->service());
        $bare = Stylist::factory()->create();

        $rows = collect($this->getJson('/api/admin/stylists?per_page=100')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(1, $rows[$ready->id]['services_count']);
        // one per upcoming date the helper opened (today and the next 90 days)
        $this->assertSame(91, $rows[$ready->id]['upcoming_days_count']);
        $this->assertSame(0, $rows[$bare->id]['services_count']);
        $this->assertSame(0, $rows[$bare->id]['upcoming_days_count']);
    }
}
