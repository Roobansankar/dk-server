<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Stylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class RazorpayPaymentTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    private function fakeOrder(string $orderId = 'order_test123'): void
    {
        Http::fake([
            'api.razorpay.com/*' => Http::response([
                'id' => $orderId,
                'entity' => 'order',
                'amount' => 15000,
                'currency' => 'INR',
                'status' => 'created',
            ], 200),
        ]);
    }

    /** One fake that hands back a distinct order id per appointment (keyed by receipt = reference). */
    private function fakeOrdersKeyedByReceipt(): void
    {
        Http::fake([
            'api.razorpay.com/*' => function ($request) {
                $receipt = $request->data()['receipt'] ?? 'unknown';

                return Http::response([
                    'id' => 'order_'.$receipt,
                    'entity' => 'order',
                    'amount' => $request->data()['amount'] ?? 0,
                    'currency' => 'INR',
                    'status' => 'created',
                ], 200);
            },
        ]);
    }

    private function signature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, config('services.razorpay.secret'));
    }

    private function pendingAppointment(array $overrides = []): Appointment
    {
        $stylist = Stylist::factory()->create();

        return Appointment::factory()
            ->pending()
            ->forStylist($stylist)
            ->create(array_merge([
                'advance_percentage' => 20,
                'service_price' => 1500,
                'advance_amount' => 300,
                'appointment_date' => now()->addDays(3)->toDateString(),
                'appointment_time' => '14:00',
                'duration_minutes' => 60,
            ], $overrides));
    }

    public function test_order_endpoint_computes_amount_server_side_and_ignores_client_input(): void
    {
        $this->fakeOrder();
        $appointment = $this->pendingAppointment();

        $response = $this->postJson("/api/appointments/{$appointment->id}/payment/order", [
            // Deliberately hostile input — must be ignored entirely.
            'amount' => 1,
        ])->assertOk();

        $response->assertJsonPath('data.amount', 30000); // 300.00 INR -> paise
        $response->assertJsonPath('data.order_id', 'order_test123');
        $response->assertJsonPath('data.key', config('services.razorpay.key'));
        $this->assertArrayNotHasKey('secret', $response->json('data'));

        $appointment->refresh();
        $this->assertSame('order_test123', $appointment->razorpay_order_id);
    }

    public function test_order_endpoint_reuses_existing_order_on_retry(): void
    {
        $this->fakeOrder('order_first');
        $appointment = $this->pendingAppointment();

        $this->postJson("/api/appointments/{$appointment->id}/payment/order")->assertOk();

        // A second call must reuse the stored order rather than asking
        // Razorpay for a new one.
        $response = $this->postJson("/api/appointments/{$appointment->id}/payment/order")->assertOk();

        $response->assertJsonPath('data.order_id', 'order_first');
        Http::assertSentCount(1);
    }

    public function test_verify_confirms_appointment_on_valid_signature(): void
    {
        $this->fakeOrder('order_abc');
        $appointment = $this->pendingAppointment();
        $this->postJson("/api/appointments/{$appointment->id}/payment/order")->assertOk();

        $signature = $this->signature('order_abc', 'pay_abc');

        $this->postJson("/api/appointments/{$appointment->id}/payment/verify", [
            'razorpay_order_id' => 'order_abc',
            'razorpay_payment_id' => 'pay_abc',
            'razorpay_signature' => $signature,
        ])->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CONFIRMED)
            ->assertJsonPath('data.payment_status', Appointment::PAYMENT_ADVANCE_PAID);

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->status);
        $this->assertSame(Appointment::PAYMENT_ADVANCE_PAID, $appointment->payment_status);
        $this->assertSame('pay_abc', $appointment->razorpay_payment_id);
    }

    public function test_verify_rejects_invalid_signature_and_appointment_stays_unconfirmed(): void
    {
        $this->fakeOrder('order_bad');
        $appointment = $this->pendingAppointment();
        $this->postJson("/api/appointments/{$appointment->id}/payment/order")->assertOk();

        $this->postJson("/api/appointments/{$appointment->id}/payment/verify", [
            'razorpay_order_id' => 'order_bad',
            'razorpay_payment_id' => 'pay_bad',
            'razorpay_signature' => 'tampered-signature',
        ])->assertStatus(422)->assertJsonValidationErrors('razorpay_signature');

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_PENDING, $appointment->status);
        $this->assertSame(Appointment::PAYMENT_UNPAID, $appointment->payment_status);
        $this->assertNull($appointment->razorpay_payment_id);
    }

    public function test_verify_rejects_a_payment_for_a_different_order(): void
    {
        $this->fakeOrder('order_real');
        $appointment = $this->pendingAppointment();
        $this->postJson("/api/appointments/{$appointment->id}/payment/order")->assertOk();

        // A signature that is internally valid for a *different* order id
        // must not be accepted for this appointment.
        $signature = $this->signature('order_someone_elses', 'pay_x');

        $this->postJson("/api/appointments/{$appointment->id}/payment/verify", [
            'razorpay_order_id' => 'order_someone_elses',
            'razorpay_payment_id' => 'pay_x',
            'razorpay_signature' => $signature,
        ])->assertStatus(422)->assertJsonValidationErrors('razorpay_order_id');

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_PENDING, $appointment->status);
    }

    public function test_retrying_verify_with_the_same_successful_payment_is_idempotent(): void
    {
        $this->fakeOrder('order_dup');
        $appointment = $this->pendingAppointment();
        $this->postJson("/api/appointments/{$appointment->id}/payment/order")->assertOk();

        $signature = $this->signature('order_dup', 'pay_dup');
        $payload = [
            'razorpay_order_id' => 'order_dup',
            'razorpay_payment_id' => 'pay_dup',
            'razorpay_signature' => $signature,
        ];

        $this->postJson("/api/appointments/{$appointment->id}/payment/verify", $payload)->assertOk();
        // Second call (retry / duplicate callback) must not error and must not
        // create a second confirmation — it stays the same appointment.
        $this->postJson("/api/appointments/{$appointment->id}/payment/verify", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_CONFIRMED);

        $this->assertSame(1, Appointment::count());
    }

    public function test_retry_after_a_failed_payment_reuses_the_same_appointment_and_order(): void
    {
        $this->fakeOrder('order_retry');
        $appointment = $this->pendingAppointment();

        $this->postJson("/api/appointments/{$appointment->id}/payment/order")->assertOk();
        $this->postJson("/api/appointments/{$appointment->id}/payment/verify", [
            'razorpay_order_id' => 'order_retry',
            'razorpay_payment_id' => 'pay_wrong',
            'razorpay_signature' => 'nope',
        ])->assertStatus(422);

        // Customer retries from the same review step — same appointment id,
        // same order, this time with a genuine payment.
        $this->postJson("/api/appointments/{$appointment->id}/payment/order")
            ->assertOk()
            ->assertJsonPath('data.order_id', 'order_retry');

        $signature = $this->signature('order_retry', 'pay_right');
        $this->postJson("/api/appointments/{$appointment->id}/payment/verify", [
            'razorpay_order_id' => 'order_retry',
            'razorpay_payment_id' => 'pay_right',
            'razorpay_signature' => $signature,
        ])->assertOk();

        $this->assertSame(1, Appointment::count());
        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->fresh()->status);
    }

    public function test_verify_rejects_a_stylist_time_conflict_confirmed_by_another_payment(): void
    {
        $stylist = Stylist::factory()->create();

        $first = Appointment::factory()->pending()->forStylist($stylist)->create([
            'advance_amount' => 300,
            'appointment_date' => now()->addDays(3)->toDateString(),
            'appointment_time' => '14:00',
            'duration_minutes' => 60,
        ]);
        $second = Appointment::factory()->pending()->forStylist($stylist)->create([
            'advance_amount' => 300,
            'appointment_date' => now()->addDays(3)->toDateString(),
            'appointment_time' => '14:30', // overlaps the first booking
            'duration_minutes' => 60,
        ]);

        $this->fakeOrdersKeyedByReceipt();
        $orderId1 = 'order_'.$first->reference;
        $orderId2 = 'order_'.$second->reference;

        $this->postJson("/api/appointments/{$first->id}/payment/order")->assertOk();
        $this->postJson("/api/appointments/{$first->id}/payment/verify", [
            'razorpay_order_id' => $orderId1,
            'razorpay_payment_id' => 'pay_1',
            'razorpay_signature' => $this->signature($orderId1, 'pay_1'),
        ])->assertOk();

        $this->postJson("/api/appointments/{$second->id}/payment/order")->assertOk();
        $this->postJson("/api/appointments/{$second->id}/payment/verify", [
            'razorpay_order_id' => $orderId2,
            'razorpay_payment_id' => 'pay_2',
            'razorpay_signature' => $this->signature($orderId2, 'pay_2'),
        ])->assertStatus(422)->assertJsonValidationErrors('appointment_time');

        $this->assertSame(Appointment::STATUS_PENDING, $second->fresh()->status);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $first->fresh()->status);
    }

    public function test_offline_appointments_have_no_payment_step(): void
    {
        $appointment = Appointment::factory()->pending()->offline()->create([
            'advance_amount' => 300,
        ]);

        $this->postJson("/api/appointments/{$appointment->id}/payment/order")->assertNotFound();
    }

    /**
     * The exact end-to-end scenario Admin → Payments & Completed must reflect
     * automatically, with no admin action: an online appointment's advance
     * gets verified (order -> Checkout -> verify), and — with no further
     * request against the appointment, no "mark completed" — GET
     * /api/admin/payments already returns it, linked to the same appointment
     * row, with the right amount/source/status. Confirms App\Models\
     * Appointment::scopePaidOrCompleted() (used by PaymentReportController)
     * actually surfaces a confirmed+advance_paid row, not just a completed one.
     */
    public function test_a_verified_advance_payment_appears_in_admin_payments_immediately(): void
    {
        $this->fakeOrder('order_live123');
        $appointment = $this->pendingAppointment(['source' => Appointment::SOURCE_ONLINE]);

        $this->postJson("/api/appointments/{$appointment->id}/payment/order")->assertOk();
        $signature = $this->signature('order_live123', 'pay_live123');
        $this->postJson("/api/appointments/{$appointment->id}/payment/verify", [
            'razorpay_order_id' => 'order_live123',
            'razorpay_payment_id' => 'pay_live123',
            'razorpay_signature' => $signature,
        ])->assertOk();

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->status);
        $this->assertSame(Appointment::PAYMENT_ADVANCE_PAID, $appointment->payment_status);

        // No "mark completed", no other write — an admin hitting the payments
        // list right now (or the page's own auto-poll) must already see it.
        $this->actingAsToken($this->superadmin());
        $this->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $appointment->id)
            ->assertJsonPath('data.0.reference', $appointment->reference)
            ->assertJsonPath('data.0.status', Appointment::STATUS_CONFIRMED)
            ->assertJsonPath('data.0.payment_status', Appointment::PAYMENT_ADVANCE_PAID)
            ->assertJsonPath('data.0.source', Appointment::SOURCE_ONLINE)
            ->assertJsonPath('data.0.advance_amount', 300);
    }

    /**
     * Repeated verification of the same already-verified payment (double
     * click, replayed callback) must not duplicate the row in the payments
     * list — there's only ever one appointment/payment record.
     */
    public function test_repeated_verification_does_not_duplicate_the_payments_row(): void
    {
        $this->fakeOrder('order_repeat');
        $appointment = $this->pendingAppointment(['source' => Appointment::SOURCE_ONLINE]);
        $this->postJson("/api/appointments/{$appointment->id}/payment/order")->assertOk();

        $payload = [
            'razorpay_order_id' => 'order_repeat',
            'razorpay_payment_id' => 'pay_repeat',
            'razorpay_signature' => $this->signature('order_repeat', 'pay_repeat'),
        ];
        $this->postJson("/api/appointments/{$appointment->id}/payment/verify", $payload)->assertOk();
        $this->postJson("/api/appointments/{$appointment->id}/payment/verify", $payload)->assertOk();

        $this->actingAsToken($this->superadmin());
        $this->getJson('/api/admin/payments')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, Appointment::count());
    }

    /**
     * A failed/tampered verification must never mark the appointment as paid,
     * and must never show up in Admin → Payments & Completed.
     */
    public function test_a_failed_verification_never_appears_as_paid_in_admin_payments(): void
    {
        $this->fakeOrder('order_failcase');
        $appointment = $this->pendingAppointment(['source' => Appointment::SOURCE_ONLINE]);
        $this->postJson("/api/appointments/{$appointment->id}/payment/order")->assertOk();

        $this->postJson("/api/appointments/{$appointment->id}/payment/verify", [
            'razorpay_order_id' => 'order_failcase',
            'razorpay_payment_id' => 'pay_failcase',
            'razorpay_signature' => 'not-a-real-signature',
        ])->assertStatus(422);

        $this->actingAsToken($this->superadmin());
        $this->getJson('/api/admin/payments')->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame(Appointment::STATUS_PENDING, $appointment->fresh()->status);
        $this->assertSame(Appointment::PAYMENT_UNPAID, $appointment->fresh()->payment_status);
    }
}
