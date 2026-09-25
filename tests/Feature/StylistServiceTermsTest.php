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
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAdmins;
use Tests\Concerns\CreatesBookableStylists;
use Tests\TestCase;

/**
 * A professional's own price and advance-to-confirm % for a service. Blank
 * means "use the service's standard"; when set it is what the booking page
 * shows, what is snapshotted on the appointment, and what gets charged.
 */
class StylistServiceTermsTest extends TestCase
{
    use CreatesAdmins, CreatesBookableStylists, RefreshDatabase;

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

    /** A 60-minute service costing ₹1,500 with a 20% advance (₹300) as its standard terms. */
    private function service(?int $price = 1500, int $advancePct = 20): Service
    {
        $category = ServiceCategory::factory()->female()->create();

        return Service::factory()->forCategory($category)->create([
            'duration_minutes' => 60, 'price' => $price, 'advance_percentage' => $advancePct,
        ]);
    }

    private function stylistWith(Service $service, ?float $price = null, ?int $advancePct = null): Stylist
    {
        $stylist = $this->bookableStylistFor($service);
        $stylist->services()->updateExistingPivot($service->id, ['price' => $price, 'advance_percentage' => $advancePct]);

        return $stylist->refresh();
    }

    private function saveTerms(Stylist $stylist, array $services): TestResponse
    {
        return $this->putJson("/api/admin/stylists/{$stylist->id}/services", ['services' => $services]);
    }

    private function book(Service $service, ?Stylist $stylist = null): TestResponse
    {
        $this->actingAsToken($this->customer());

        return $this->postJson('/api/appointments', array_filter([
            'customer_name' => 'Priya R', 'phone' => '+91 9790431212', 'gender' => 'female',
            'category_id' => $service->service_category_id, 'service_id' => $service->id,
            'stylist_id' => $stylist?->id, 'appointment_date' => $this->monday, 'appointment_time' => '11:00',
        ]));
    }

    // --- The effective terms -----------------------------------------------

    public function test_a_professionals_own_price_and_advance_win_over_the_services(): void
    {
        $service = $this->service(1500, 20);
        $stylist = $this->stylistWith($service, 1200, 25);

        $this->assertSame(
            ['price' => 1200.0, 'advance_percentage' => 25, 'advance_amount' => 300.0],
            $service->termsFor($stylist),
        );
    }

    public function test_each_term_falls_back_to_the_standard_independently(): void
    {
        $service = $this->service(1500, 20);

        // only the price set → the standard 20% applies to the new price
        $this->assertSame(
            ['price' => 1000.0, 'advance_percentage' => 20, 'advance_amount' => 200.0],
            $service->termsFor($this->stylistWith($service, 1000, null)),
        );

        // only the advance set → applies to the standard price
        $this->assertSame(
            ['price' => 1500.0, 'advance_percentage' => 50, 'advance_amount' => 750.0],
            $service->termsFor($this->stylistWith($service, null, 50)),
        );

        // nothing set, or nobody chosen → the service's own terms
        $this->assertSame(
            ['price' => 1500.0, 'advance_percentage' => 20, 'advance_amount' => 300.0],
            $service->termsFor($this->stylistWith($service)),
        );
        $this->assertSame(
            ['price' => 1500.0, 'advance_percentage' => 20, 'advance_amount' => 300.0],
            $service->termsFor(null),
        );
    }

    public function test_zero_is_a_real_override_not_a_blank(): void
    {
        $service = $this->service(1500, 20);
        $stylist = $this->stylistWith($service, 0, 0);

        $this->assertSame(
            ['price' => 0.0, 'advance_percentage' => 0, 'advance_amount' => 0.0],
            $service->termsFor($stylist),
        );
    }

    // --- Admin: reading and saving ---------------------------------------------

    public function test_setup_lists_only_the_services_that_have_their_own_terms(): void
    {
        $this->actingAsToken($this->superadmin());
        $custom = $this->service();
        $standard = $this->service();
        $stylist = $this->bookableStylistFor($custom, $standard);
        $stylist->services()->updateExistingPivot($custom->id, ['price' => 1200, 'advance_percentage' => 25]);

        $terms = $this->getJson("/api/admin/stylists/{$stylist->id}/setup")->assertOk()->json('data.service_terms');

        $this->assertSame([(string) $custom->id], array_map('strval', array_keys($terms)));
        $this->assertSame(['price' => 1200, 'advance_percentage' => 25], $terms[$custom->id]);
    }

    public function test_admin_can_set_a_price_and_advance_per_service_and_blank_means_standard(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        [$a, $b, $c] = [$this->service(), $this->service(), $this->service()];

        $this->saveTerms($stylist, [
            ['id' => $a->id, 'price' => 1200, 'advance_percentage' => 25],
            ['id' => $b->id, 'price' => null, 'advance_percentage' => 40],
            ['id' => $c->id, 'price' => '', 'advance_percentage' => null],
        ])
            ->assertOk()
            ->assertJsonPath("data.service_terms.{$a->id}.price", 1200)
            ->assertJsonPath("data.service_terms.{$b->id}.advance_percentage", 40)
            ->assertJsonPath("data.service_terms.{$b->id}.price", null)
            ->assertJsonMissingPath("data.service_terms.{$c->id}");

        $pivot = fn (Service $s) => $stylist->services()->where('services.id', $s->id)->first()->pivot;
        $this->assertEquals(1200, $pivot($a)->price);
        $this->assertNull($pivot($b)->price);
        $this->assertNull($pivot($c)->price);
        $this->assertNull($pivot($c)->advance_percentage);
    }

    public function test_saving_replaces_the_offered_set_and_drops_the_terms_of_removed_services(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        [$a, $b] = [$this->service(), $this->service()];
        $this->saveTerms($stylist, [
            ['id' => $a->id, 'price' => 1200, 'advance_percentage' => 25],
            ['id' => $b->id, 'price' => 900, 'advance_percentage' => 10],
        ])->assertOk();

        // b is no longer offered; a's terms are edited
        $this->saveTerms($stylist, [['id' => $a->id, 'price' => 1300, 'advance_percentage' => 30]])
            ->assertOk()
            ->assertJsonPath('data.service_ids', [$a->id])
            ->assertJsonPath("data.service_terms.{$a->id}.price", 1300);

        $this->assertSame(1, $stylist->services()->count());
        $this->assertDatabaseMissing('stylist_service', ['stylist_id' => $stylist->id, 'service_id' => $b->id]);
    }

    public function test_sending_only_service_ids_keeps_the_terms_already_set(): void
    {
        $this->actingAsToken($this->superadmin());
        [$a, $b] = [$this->service(), $this->service()];
        $stylist = $this->stylistWith($a, 1200, 25);

        $this->putJson("/api/admin/stylists/{$stylist->id}/services", ['service_ids' => [$a->id, $b->id]])->assertOk();

        $this->assertEquals(1200, $stylist->services()->where('services.id', $a->id)->first()->pivot->price);
    }

    /** @param  array<string, mixed>  $entry */
    #[DataProvider('invalidTerms')]
    public function test_invalid_terms_are_rejected(array $entry, string $errorKey): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $service = $this->service();

        $this->saveTerms($stylist, [['id' => $service->id] + $entry])
            ->assertStatus(422)->assertJsonValidationErrors($errorKey);
    }

    public static function invalidTerms(): array
    {
        return [
            'negative price' => [['price' => -1], 'services.0.price'],
            'price is not a number' => [['price' => 'free'], 'services.0.price'],
            'absurd price' => [['price' => 99999999], 'services.0.price'],
            'advance above 100' => [['advance_percentage' => 101], 'services.0.advance_percentage'],
            'negative advance' => [['advance_percentage' => -5], 'services.0.advance_percentage'],
            'fractional advance' => [['advance_percentage' => 12.5], 'services.0.advance_percentage'],
        ];
    }

    public function test_unknown_or_repeated_services_are_rejected(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $service = $this->service();

        $this->saveTerms($stylist, [['id' => 999999]])->assertStatus(422)->assertJsonValidationErrors('services.0.id');
        $this->saveTerms($stylist, [['id' => $service->id], ['id' => $service->id]])
            ->assertStatus(422)->assertJsonValidationErrors('services.1.id');
    }

    public function test_only_managers_can_change_the_terms(): void
    {
        $stylist = Stylist::factory()->create();
        $service = $this->service();

        $this->saveTerms($stylist, [['id' => $service->id, 'price' => 1]])->assertUnauthorized();

        $this->actingAsToken($this->userWith(['stylists.view']));
        $this->saveTerms($stylist, [['id' => $service->id, 'price' => 1]])->assertForbidden();
    }

    public function test_the_public_roster_and_the_admin_list_expose_each_professionals_terms(): void
    {
        $service = $this->service();
        $stylist = $this->stylistWith($service, 1200, 25);
        $plain = $this->bookableStylistFor($service);

        $publicRows = collect($this->getJson('/api/stylists')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['price' => 1200, 'advance_percentage' => 25], $publicRows[$stylist->id]['service_terms'][$service->id]);
        $this->assertSame([], (array) $publicRows[$plain->id]['service_terms']);

        $this->actingAsToken($this->superadmin());
        $adminRows = collect($this->getJson('/api/admin/stylists?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([$service->id], $adminRows[$stylist->id]['service_ids']);
        $this->assertSame(1200, $adminRows[$stylist->id]['service_terms'][$service->id]['price']);
    }

    // --- Booking, snapshot and payment -------------------------------------------

    public function test_a_booking_snapshots_the_chosen_professionals_price_and_advance(): void
    {
        $service = $this->service(1500, 20);
        $stylist = $this->stylistWith($service, 1200, 25);

        $this->book($service, $stylist)
            ->assertCreated()
            ->assertJsonPath('data.service_price', fn ($v) => (float) $v === 1200.0)
            ->assertJsonPath('data.advance_percentage', 25)
            ->assertJsonPath('data.advance_amount', fn ($v) => (float) $v === 300.0)
            ->assertJsonPath('data.remaining_amount', fn ($v) => (float) $v === 1200.0);

        $this->assertDatabaseHas('appointments', ['stylist_id' => $stylist->id, 'service_price' => 1200, 'advance_percentage' => 25, 'advance_amount' => 300]);
    }

    public function test_two_professionals_can_charge_differently_for_the_same_service(): void
    {
        $service = $this->service(1500, 20);
        $premium = $this->stylistWith($service, 2500, 50);
        $standard = $this->stylistWith($service); // no overrides

        $this->book($service, $premium)->assertCreated()
            ->assertJsonPath('data.advance_amount', fn ($v) => (float) $v === 1250.0);
        $this->book($service, $standard)->assertCreated()
            ->assertJsonPath('data.service_price', fn ($v) => (float) $v === 1500.0)
            ->assertJsonPath('data.advance_amount', fn ($v) => (float) $v === 300.0);
    }

    public function test_a_zero_advance_override_means_nothing_to_pay_up_front(): void
    {
        $service = $this->service(1500, 20);
        $stylist = $this->stylistWith($service, null, 0);

        $this->book($service, $stylist)->assertCreated()
            ->assertJsonPath('data.advance_percentage', 0)
            ->assertJsonPath('data.advance_amount', fn ($v) => (float) $v === 0.0);
    }

    public function test_any_professional_bookings_take_the_assigned_professionals_terms(): void
    {
        $service = $this->service(1500, 20);
        $this->stylistWith($service, 1800, 30);

        $this->book($service)->assertCreated()
            ->assertJsonPath('data.service_price', fn ($v) => (float) $v === 1800.0)
            ->assertJsonPath('data.advance_amount', fn ($v) => (float) $v === 540.0);
    }

    public function test_changing_the_terms_later_does_not_touch_appointments_already_made(): void
    {
        $this->actingAsToken($this->superadmin());
        $service = $this->service(1500, 20);
        $stylist = $this->stylistWith($service, 1200, 25);
        $reference = $this->book($service, $stylist)->assertCreated()->json('data.reference');

        $this->actingAsToken($this->superadmin());
        $this->saveTerms($stylist, [['id' => $service->id, 'price' => 3000, 'advance_percentage' => 90]])->assertOk();

        $appointment = Appointment::where('reference', $reference)->firstOrFail();
        $this->assertEquals(1200, $appointment->service_price);
        $this->assertEquals(300, $appointment->advance_amount);
    }

    public function test_the_payment_order_charges_the_professionals_advance(): void
    {
        Http::fake(['api.razorpay.com/*' => fn ($request) => Http::response([
            'id' => 'order_terms', 'entity' => 'order', 'amount' => $request->data()['amount'] ?? 0, 'currency' => 'INR', 'status' => 'created',
        ], 200)]);

        $service = $this->service(1500, 20);
        $stylist = $this->stylistWith($service, 1200, 25); // advance = ₹300
        $service->update(['advance_percentage' => 10]);    // standard would be ₹150 — must not be used

        $id = $this->book($service, $stylist)->assertCreated()->json('data.id');

        $this->postJson("/api/appointments/{$id}/payment/order")
            ->assertOk()
            ->assertJsonPath('data.amount', 30000); // ₹300.00 in paise
    }

    public function test_offline_bookings_by_staff_use_the_professionals_terms_too(): void
    {
        $this->actingAsToken($this->superadmin());
        $service = $this->service(1500, 20);
        $stylist = $this->stylistWith($service, 1200, 25);

        $this->postJson('/api/admin/appointments', [
            'customer_name' => 'Walk-in Guest', 'phone' => '+91 9876543210', 'gender' => 'female',
            'category_id' => $service->service_category_id, 'service_id' => $service->id, 'stylist_id' => $stylist->id,
            'appointment_date' => $this->monday, 'appointment_time' => '15:30',
            'payment_status' => 'unpaid', 'status' => 'pending',
        ])
            ->assertCreated()
            ->assertJsonPath('data.service_price', fn ($v) => (float) $v === 1200.0)
            ->assertJsonPath('data.advance_amount', fn ($v) => (float) $v === 300.0);
    }
}
