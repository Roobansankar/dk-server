<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Stylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

/**
 * Logical pins for the double-submit/concurrent-booking fix (see
 * PaymentController::verify and Public\AppointmentController::store).
 *
 * PHPUnit's RefreshDatabase wraps each test in a single outer transaction,
 * so a genuinely concurrent second DB connection can't be exercised here —
 * that's covered separately by a live two-process race against the real
 * MySQL dev server (see the task report). What these tests pin is the
 * SEQUENTIAL correctness the concurrent case must reduce to once the lock
 * ordering serialises two overlapping requests: whichever one reaches the
 * transaction first wins, the second gets a clean 422, and unrelated
 * stylists/times are never blocked by each other.
 */
class BookingConcurrencyTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

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

    private function service(int $minutes = 40): Service
    {
        $category = ServiceCategory::factory()->female()->create();

        return Service::factory()->forCategory($category)->create([
            'duration_minutes' => $minutes,
            'price' => 1500,
            'advance_percentage' => 20,
        ]);
    }

    private function pendingOnline(Stylist $stylist, Service $service, string $date, string $time): Appointment
    {
        return Appointment::factory()
            ->pending()
            ->forStylist($stylist)
            ->forService($service)
            ->create([
                'appointment_date' => $date,
                'appointment_time' => $time,
                'advance_amount' => 300,
            ]);
    }

    private function pay(Appointment $appointment): TestResponse
    {
        $orderId = 'order_'.$appointment->reference;
        $paymentId = 'pay_'.$appointment->id;

        $this->postJson("/api/appointments/{$appointment->id}/payment/order")->assertOk();

        return $this->postJson("/api/appointments/{$appointment->id}/payment/verify", [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $this->signature($orderId, $paymentId),
        ]);
    }

    public function test_exact_same_stylist_date_and_time_only_one_confirmation_wins(): void
    {
        $this->fakeOrdersKeyedByReceipt();
        $stylist = Stylist::factory()->create();
        $service = $this->service();
        $date = now()->addDays(5)->toDateString();

        $first = $this->pendingOnline($stylist, $service, $date, '15:00');
        $second = $this->pendingOnline($stylist, $service, $date, '15:00');

        $this->pay($first)->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->pay($second)
            ->assertStatus(422)
            ->assertJsonValidationErrors('appointment_time');

        $this->assertSame(Appointment::STATUS_CONFIRMED, $first->fresh()->status);
        $this->assertSame(Appointment::STATUS_PENDING, $second->fresh()->status);

        // Exactly one CONFIRMED appointment holds this stylist/date/time.
        $this->assertSame(1, Appointment::query()
            ->where('stylist_id', $stylist->id)
            ->whereDate('appointment_date', $date)
            ->where('appointment_time', '15:00')
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->count());
    }

    public function test_different_stylists_can_both_confirm_the_exact_same_time(): void
    {
        $this->fakeOrdersKeyedByReceipt();
        $stylistA = Stylist::factory()->create();
        $stylistB = Stylist::factory()->create();
        $service = $this->service();
        $date = now()->addDays(5)->toDateString();

        $a = $this->pendingOnline($stylistA, $service, $date, '15:00');
        $b = $this->pendingOnline($stylistB, $service, $date, '15:00');

        $this->pay($a)->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->pay($b)->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_10_minute_buffer_still_blocks_confirmation_after_the_slot_lock_fix(): void
    {
        $this->fakeOrdersKeyedByReceipt();
        $stylist = Stylist::factory()->create();
        $service = $this->service(40);
        $date = now()->addDays(5)->toDateString();

        $first = $this->pendingOnline($stylist, $service, $date, '15:00'); // 15:00-15:40
        // 15:45 is only 5 minutes after the 15:40 end — inside the 10-minute buffer.
        $second = $this->pendingOnline($stylist, $service, $date, '15:45');

        $this->pay($first)->assertOk();
        $this->pay($second)->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    /**
     * Same fix, admin side: Admin\AppointmentController::confirm() used to
     * lock this appointment's own row BEFORE AppointmentSlots::lockDay() —
     * the same lock-order inversion that could deadlock two concurrent
     * PaymentController::verify() calls. Sequentially this reduces to
     * "second one loses cleanly", exactly like the public payment flow.
     */
    public function test_admin_exact_same_stylist_date_and_time_only_one_admin_confirmation_wins(): void
    {
        $this->actingAsToken($this->superadmin());
        $stylist = Stylist::factory()->create();
        $service = $this->service();
        $date = now()->addDays(5)->toDateString();

        $first = Appointment::factory()->pending()->forStylist($stylist)->forService($service)
            ->create(['appointment_date' => $date, 'appointment_time' => '15:00']);
        $second = Appointment::factory()->pending()->forStylist($stylist)->forService($service)
            ->create(['appointment_date' => $date, 'appointment_time' => '15:00']);

        $this->postJson("/api/admin/appointments/{$first->id}/confirm")
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/admin/appointments/{$second->id}/confirm")
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');

        $this->assertSame(1, Appointment::query()
            ->where('stylist_id', $stylist->id)
            ->whereDate('appointment_date', $date)
            ->where('appointment_time', '15:00')
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->count());
    }

    public function test_creation_atomically_rejects_a_slot_already_confirmed_by_another_appointment(): void
    {
        $this->actingAsToken($this->customer());
        $stylist = Stylist::factory()->create();
        $service = $this->service(40);
        $date = now()->addDays(5)->toDateString();

        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => $date,
            'appointment_time' => '15:00',
        ]);

        // Inside the buffered lockout of the confirmed 15:00-15:40 appointment.
        $this->postJson('/api/appointments', [
            'customer_name' => 'Losing Request',
            'phone' => '+91 9790400000',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'stylist_id' => $stylist->id,
            'appointment_date' => $date,
            'appointment_time' => '15:20',
        ])->assertStatus(422)->assertJsonValidationErrors('appointment_time');

        // A non-conflicting create for the same stylist/day still succeeds —
        // the day-lock only serialises, it doesn't over-block.
        $this->postJson('/api/appointments', [
            'customer_name' => 'Different Slot',
            'phone' => '+91 9790400001',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'stylist_id' => $stylist->id,
            'appointment_date' => $date,
            'appointment_time' => '17:00',
        ])->assertCreated();
    }
}
