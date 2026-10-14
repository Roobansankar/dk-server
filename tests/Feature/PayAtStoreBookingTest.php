<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SiteSetting;
use App\Models\Stylist;
use App\Support\PayAtStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAdmins;
use Tests\Concerns\CreatesBookableStylists;
use Tests\TestCase;

/**
 * Online "Pay at Store": a booking whose full price is within the admin's
 * saved limit is confirmed straight away, unpaid, with the bill due at the
 * studio — decided server-side against the live limit, never the client.
 */
class PayAtStoreBookingTest extends TestCase
{
    use CreatesAdmins, CreatesBookableStylists, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1347967731728408',
            'services.whatsapp.language' => 'en_US',
            'services.whatsapp.template' => 'booking_confirmed',
            'services.whatsapp.template_booked' => 'appointment_booked2',
            'services.whatsapp.template_paid' => 'payment_received',
            'services.whatsapp.owner_phone' => '9876500001',
            'services.whatsapp.template_owner_booking' => 'owner_booking_alert_v2',
        ]);

        Http::fake(fn (Request $request) => Http::response(['messages' => [['id' => 'wamid.test']]], 200));
    }

    private function setLimit(?string $value): void
    {
        SiteSetting::where('key', PayAtStore::SETTING_KEY)->update(['value' => $value]);
        Cache::flush();
    }

    private function service(float $price, int $minutes = 60, ?ServiceCategory $category = null): Service
    {
        return Service::factory()
            ->forCategory($category ?? ServiceCategory::factory()->female()->create())
            ->create(['price' => $price, 'advance_percentage' => 20, 'duration_minutes' => $minutes]);
    }

    private function date(): string
    {
        return now('Asia/Kolkata')->addDays(3)->toDateString();
    }

    private function payload(Service $service, Stylist $stylist, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Priya R',
            'phone' => '8072427484',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'stylist_id' => $stylist->id,
            'appointment_date' => $this->date(),
            'appointment_time' => '11:00',
            'payment_option' => 'pay_at_store',
        ], $overrides);
    }

    /** @return array<int, string> template names sent to Meta, in order */
    private function templatesSent(): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages'))
            ->map(fn (Request $r) => $r->data()['template']['name'] ?? null)
            ->values()
            ->all();
    }

    private function razorpayCalls(): int
    {
        return Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), 'razorpay'))->count();
    }

    // --- Setting ----------------------------------------------------------

    public function test_migration_adds_an_empty_limit_so_pay_at_store_starts_off(): void
    {
        $row = SiteSetting::where('key', PayAtStore::SETTING_KEY)->first();

        $this->assertNotNull($row);
        $this->assertNull($row->value);
        $this->assertSame('currency', $row->type);
        $this->assertSame('payments', $row->group);
        $this->assertNull(PayAtStore::limit());
    }

    public function test_admin_can_save_the_limit_and_it_persists_and_reaches_public_settings(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->putJson('/api/admin/site-settings', ['settings' => ['pay_at_store_max_amount' => '2000']])
            ->assertOk();

        Cache::flush();
        $this->getJson('/api/admin/site-settings')
            ->assertOk()
            ->assertJsonFragment(['key' => 'pay_at_store_max_amount', 'value' => '2000', 'type' => 'currency', 'group' => 'payments']);
        $this->getJson('/api/site-settings')->assertOk()->assertJsonPath('data.pay_at_store_max_amount', '2000');
        $this->assertSame(2000.0, PayAtStore::limit());

        $this->putJson('/api/admin/site-settings', ['settings' => ['pay_at_store_max_amount' => '3000.50']])->assertOk();
        $this->assertSame(3000.5, PayAtStore::limit());
    }

    public function test_admin_limit_is_validated_as_a_non_negative_amount(): void
    {
        $this->actingAsToken($this->superadmin());

        foreach (['-1', 'abc', '100.123', '2,000'] as $bad) {
            $this->putJson('/api/admin/site-settings', ['settings' => ['pay_at_store_max_amount' => $bad]])
                ->assertStatus(422)
                ->assertJsonValidationErrors('settings.pay_at_store_max_amount');
        }

        $this->putJson('/api/admin/site-settings', ['settings' => ['pay_at_store_max_amount' => '0']])->assertOk();
        $this->assertSame(0.0, PayAtStore::limit());

        // Blank switches it off.
        $this->putJson('/api/admin/site-settings', ['settings' => ['pay_at_store_max_amount' => '']])->assertOk();
        $this->assertNull(PayAtStore::limit());
    }

    public function test_only_settings_managers_can_change_the_limit(): void
    {
        $this->actingAsToken($this->userWith(['settings.view']));

        $this->putJson('/api/admin/site-settings', ['settings' => ['pay_at_store_max_amount' => '5000']])
            ->assertForbidden();
        $this->assertNull(PayAtStore::limit());
    }

    // --- Eligibility ------------------------------------------------------

    public function test_services_below_and_exactly_at_the_limit_can_pay_at_store(): void
    {
        $this->setLimit('2000');

        foreach ([1500, 2000] as $i => $price) {
            $service = $this->service($price);
            $stylist = $this->bookableStylistFor($service);

            $this->postJson('/api/appointments', $this->payload($service, $stylist, ['phone' => "900000000{$i}"]))
                ->assertCreated()
                ->assertJsonPath('data.status', Appointment::STATUS_CONFIRMED)
                ->assertJsonPath('data.payment_status', Appointment::PAYMENT_UNPAID)
                ->assertJsonPath('data.payment_method', Appointment::PAYMENT_METHOD_PAY_AT_STORE)
                ->assertJsonPath('data.service_price', $price);
        }
    }

    public function test_service_above_the_limit_is_rejected_and_nothing_is_booked(): void
    {
        $this->setLimit('2000');
        $service = $this->service(2000.01);
        $stylist = $this->bookableStylistFor($service);

        $this->postJson('/api/appointments', $this->payload($service, $stylist))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_option');

        $this->assertSame(0, Appointment::count());
        $this->assertSame([], $this->templatesSent());
    }

    public function test_changing_the_limit_changes_eligibility_at_submit_time(): void
    {
        $service = $this->service(2500);
        $stylist = $this->bookableStylistFor($service);

        $this->setLimit('2000');
        $this->postJson('/api/appointments', $this->payload($service, $stylist))->assertStatus(422);

        $this->setLimit('3000');
        $this->postJson('/api/appointments', $this->payload($service, $stylist))->assertCreated();

        // Lowered again after the page loaded: re-checked, rejected.
        $this->setLimit('1000');
        $this->postJson('/api/appointments', $this->payload($service, $stylist, ['appointment_time' => '15:00', 'phone' => '9000000009']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_option');
    }

    public function test_missing_or_invalid_limit_disables_pay_at_store(): void
    {
        $service = $this->service(100);
        $stylist = $this->bookableStylistFor($service);

        foreach ([null, '', 'abc', '-5'] as $value) {
            $this->setLimit($value);
            $this->assertNull(PayAtStore::limit());
            $this->postJson('/api/appointments', $this->payload($service, $stylist))
                ->assertStatus(422)
                ->assertJsonValidationErrors('payment_option');
        }

        // Setting row deleted entirely — still off, never assumed.
        SiteSetting::where('key', PayAtStore::SETTING_KEY)->delete();
        Cache::flush();
        $this->postJson('/api/appointments', $this->payload($service, $stylist))->assertStatus(422);

        $this->assertSame(0, Appointment::count());
    }

    public function test_client_supplied_price_limit_or_eligibility_is_ignored(): void
    {
        $this->setLimit('2000');
        $service = $this->service(5000);
        $stylist = $this->bookableStylistFor($service);

        $this->postJson('/api/appointments', $this->payload($service, $stylist, [
            'service_price' => 100,
            'price' => 100,
            'pay_at_store_max_amount' => 999999,
            'eligible' => true,
            'payment_method' => 'pay_at_store',
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]))->assertStatus(422)->assertJsonValidationErrors('payment_option');

        $this->assertSame(0, Appointment::count());
    }

    public function test_the_professionals_own_price_is_what_is_checked(): void
    {
        $this->setLimit('2000');

        // Base price within the limit, but this professional charges more.
        $cheapBase = $this->service(1500);
        $pricey = $this->bookableStylistFor($cheapBase);
        $pricey->services()->updateExistingPivot($cheapBase->id, ['price' => 2500]);

        $this->postJson('/api/appointments', $this->payload($cheapBase, $pricey))
            ->assertStatus(422)->assertJsonValidationErrors('payment_option');

        // Base price over the limit, but this professional charges less.
        $dearBase = $this->service(2500);
        $cheap = $this->bookableStylistFor($dearBase);
        $cheap->services()->updateExistingPivot($dearBase->id, ['price' => 1800]);

        $this->postJson('/api/appointments', $this->payload($dearBase, $cheap))
            ->assertCreated()
            ->assertJsonPath('data.service_price', 1800);
    }

    public function test_multi_service_bookings_use_the_combined_full_price(): void
    {
        $category = ServiceCategory::factory()->female()->create();
        $a = $this->service(1200, 30, $category);
        $b = $this->service(1000, 30, $category);
        $stylist = $this->bookableStylistFor($a, $b);
        $payload = $this->payload($a, $stylist, ['service_ids' => [$a->id, $b->id]]);

        $this->setLimit('2000');
        $this->postJson('/api/appointments', $payload)->assertStatus(422)->assertJsonValidationErrors('payment_option');

        $this->setLimit('2200');
        $this->postJson('/api/appointments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.service_price', 2200)
            ->assertJsonPath('data.duration_minutes', 60);
    }

    public function test_pay_at_store_requires_a_named_professional_and_a_known_option(): void
    {
        $this->setLimit('2000');
        $service = $this->service(1000);
        $stylist = $this->bookableStylistFor($service);

        $this->postJson('/api/appointments', $this->payload($service, $stylist, ['stylist_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('stylist_id');

        $this->postJson('/api/appointments', $this->payload($service, $stylist, ['payment_option' => 'free']))
            ->assertStatus(422)->assertJsonValidationErrors('payment_option');
    }

    // --- Booking, slots, retries -----------------------------------------

    public function test_pay_at_store_booking_is_confirmed_unpaid_and_never_paid_online(): void
    {
        $this->setLimit('2000');
        $service = $this->service(1500);
        $stylist = $this->bookableStylistFor($service);

        $id = $this->postJson('/api/appointments', $this->payload($service, $stylist))
            ->assertCreated()
            ->assertJsonPath('data.amount_received', 0)
            ->assertJsonPath('data.remaining_amount', 1500)
            ->json('data.id');

        $appointment = Appointment::findOrFail($id);
        $this->assertSame(Appointment::SOURCE_ONLINE, $appointment->source);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->status);
        $this->assertSame(Appointment::PAYMENT_UNPAID, $appointment->payment_status);
        $this->assertSame(Appointment::PAYMENT_METHOD_PAY_AT_STORE, $appointment->payment_method);
        $this->assertNull($appointment->razorpay_order_id);
        $this->assertNull($appointment->razorpay_payment_id);
        $this->assertNull($appointment->balance_payment_method);

        // Razorpay is never contacted, and its order endpoint refuses this booking.
        $this->assertSame(0, $this->razorpayCalls());
        $this->postJson("/api/appointments/{$id}/payment/order")->assertStatus(422);
        $this->assertSame(0, $this->razorpayCalls());
    }

    public function test_pay_at_store_booking_reserves_its_slot_against_competing_bookings(): void
    {
        $this->setLimit('2000');
        $service = $this->service(1500);
        $stylist = $this->bookableStylistFor($service);

        $this->postJson('/api/appointments', $this->payload($service, $stylist))->assertCreated();

        // Busy lookup shows it.
        $this->getJson("/api/appointments/busy?stylist_id={$stylist->id}&date={$this->date()}")
            ->assertOk()->assertJsonCount(1, 'data');

        // Another customer, same slot — Pay at Store or advance — is refused.
        $other = ['phone' => '9123456780', 'customer_name' => 'Someone Else'];
        $this->postJson('/api/appointments', $this->payload($service, $stylist, $other))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
        $this->postJson('/api/appointments', $this->payload($service, $stylist, $other + ['payment_option' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
        // Overlapping start inside the buffer is refused too.
        $this->postJson('/api/appointments', $this->payload($service, $stylist, $other + ['appointment_time' => '11:30']))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');

        $this->assertSame(1, Appointment::count());
    }

    public function test_unavailable_slots_are_still_rejected_for_pay_at_store(): void
    {
        $this->setLimit('2000');
        $service = $this->service(1500);
        $stylist = $this->bookableStylistFor($service);

        // Inside the professional's 1–2 PM break, before opening, and in the past.
        foreach ([
            ['appointment_time' => '13:00'],
            ['appointment_time' => '08:00'],
            ['appointment_date' => now('Asia/Kolkata')->subDay()->toDateString()],
        ] as $override) {
            $this->postJson('/api/appointments', $this->payload($service, $stylist, $override))->assertStatus(422);
        }

        $this->assertSame(0, Appointment::count());
    }

    public function test_repeated_submissions_return_the_same_booking_without_duplicate_notifications(): void
    {
        $this->setLimit('2000');
        $service = $this->service(1500);
        $stylist = $this->bookableStylistFor($service);

        $first = $this->postJson('/api/appointments', $this->payload($service, $stylist))->assertCreated();
        $second = $this->postJson('/api/appointments', $this->payload($service, $stylist))->assertOk();
        $third = $this->postJson('/api/appointments', $this->payload($service, $stylist))->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.id'), $third->json('data.id'));
        $this->assertSame(1, Appointment::count());
        $this->assertSame(['appointment_booked2', 'owner_booking_alert_v2'], $this->templatesSent());
    }

    public function test_whatsapp_uses_the_existing_unpaid_booking_messages_not_a_payment_one(): void
    {
        $this->setLimit('2000');
        $service = $this->service(1500);
        $stylist = $this->bookableStylistFor($service);

        $this->postJson('/api/appointments', $this->payload($service, $stylist))->assertCreated();

        $sent = $this->templatesSent();
        $this->assertSame(['appointment_booked2', 'owner_booking_alert_v2'], $sent);
        $this->assertNotContains('booking_confirmed', $sent);
        $this->assertNotContains('payment_received', $sent);
    }

    // --- Advance path unchanged ------------------------------------------

    public function test_advance_payment_flow_is_unchanged(): void
    {
        $this->setLimit('2000');
        $service = $this->service(1500);
        $stylist = $this->bookableStylistFor($service);

        foreach ([['payment_option' => null], ['payment_option' => 'advance', 'appointment_time' => '15:00']] as $i => $override) {
            $payload = $this->payload($service, $stylist, $override + ['phone' => "911111111{$i}"]);
            if ($override['payment_option'] === null) {
                unset($payload['payment_option']);
            }

            $this->postJson('/api/appointments', $payload)
                ->assertCreated()
                ->assertJsonPath('data.status', Appointment::STATUS_PENDING)
                ->assertJsonPath('data.payment_status', Appointment::PAYMENT_UNPAID)
                ->assertJsonPath('data.advance_amount', 300)
                ->assertJsonPath('message', 'Your appointment request has been received. The studio will confirm it shortly.')
                ->assertJsonMissingPath('data.payment_method');
        }

        $this->assertSame(0, Appointment::whereNotNull('payment_method')->count());
        // A pending advance request sends nothing until it's paid/confirmed.
        $this->assertSame([], $this->templatesSent());
    }

    // --- Admin display / reporting ---------------------------------------

    public function test_admin_sees_it_as_confirmed_unpaid_pay_at_store_and_reports_stay_correct(): void
    {
        $this->setLimit('2000');
        $service = $this->service(1500);
        $stylist = $this->bookableStylistFor($service);
        $id = $this->postJson('/api/appointments', $this->payload($service, $stylist))->json('data.id');

        $this->actingAsToken($this->superadmin());

        $this->getJson('/api/admin/appointments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.status', Appointment::STATUS_CONFIRMED)
            ->assertJsonPath('data.0.payment_status', Appointment::PAYMENT_UNPAID)
            ->assertJsonPath('data.0.payment_method', Appointment::PAYMENT_METHOD_PAY_AT_STORE);

        // Unpaid filter finds it; the payments report does not count it yet.
        $this->getJson('/api/admin/appointments?payment_status=unpaid')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/payments')->assertOk()->assertJsonCount(0, 'data');

        // The method itself can't be edited on an online booking (existing rule).
        $this->patchJson("/api/admin/appointments/{$id}", ['payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('payment_method');

        // Paid at the studio: the existing balance workflow records how.
        $this->patchJson("/api/admin/appointments/{$id}", ['payment_status' => 'paid', 'balance_payment_method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', Appointment::PAYMENT_PAID)
            ->assertJsonPath('data.payment_method', Appointment::PAYMENT_METHOD_PAY_AT_STORE)
            ->assertJsonPath('data.balance_payment_method', 'cash')
            ->assertJsonPath('data.amount_received', 1500)
            ->assertJsonPath('data.remaining_amount', 0);

        $this->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);
    }
}
