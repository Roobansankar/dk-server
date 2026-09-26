<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

/**
 * Online bookings: the advance is paid through Razorpay; the remaining balance
 * is paid at the salon and staff record how (upi | card | cash) separately.
 */
class OnlineBalancePaymentMethodTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    private function advancePaidOnlineAppointment(): Appointment
    {
        $category = ServiceCategory::factory()->female()->create();
        $service = Service::factory()->forCategory($category)->create([
            'price' => 600, 'advance_percentage' => 10,
        ]);

        $appointment = Appointment::factory()->forService($service)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'payment_status' => Appointment::PAYMENT_ADVANCE_PAID,
        ]);
        $appointment->forceFill([
            'razorpay_order_id' => 'order_TEST'.$appointment->id,
            'razorpay_payment_id' => 'pay_TEST'.$appointment->id,
        ])->save();

        return $appointment->fresh();
    }

    public function test_balance_payment_method_is_null_until_the_balance_is_paid(): void
    {
        $this->actingAsToken($this->superadmin());
        $appointment = $this->advancePaidOnlineAppointment();

        $this->getJson("/api/admin/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'advance_paid')
            ->assertJsonPath('data.balance_payment_method', null)
            ->assertJsonPath('data.remaining_amount', fn ($v) => (float) $v === 540.0);

        $this->assertNull($appointment->fresh()->balance_payment_method);
    }

    public function test_each_balance_payment_method_is_stored_and_returned(): void
    {
        $this->actingAsToken($this->superadmin());

        foreach (['upi', 'card', 'cash'] as $method) {
            $appointment = $this->advancePaidOnlineAppointment();

            $this->patchJson("/api/admin/appointments/{$appointment->id}", [
                'payment_status' => 'paid',
                'balance_payment_method' => $method,
            ])
                ->assertOk()
                ->assertJsonPath('data.payment_status', 'paid')
                ->assertJsonPath('data.balance_payment_method', $method);

            $this->assertDatabaseHas('appointments', [
                'id' => $appointment->id,
                'balance_payment_method' => $method,
                'payment_method' => null,
            ]);
            $this->getJson("/api/admin/appointments/{$appointment->id}")
                ->assertOk()
                ->assertJsonPath('data.balance_payment_method', $method);
        }
    }

    public function test_recording_the_balance_method_leaves_razorpay_advance_data_unchanged(): void
    {
        $this->actingAsToken($this->superadmin());
        $appointment = $this->advancePaidOnlineAppointment();

        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['balance_payment_method' => 'upi'])
            ->assertOk();

        $fresh = $appointment->fresh();
        $this->assertSame($appointment->razorpay_order_id, $fresh->razorpay_order_id);
        $this->assertSame($appointment->razorpay_payment_id, $fresh->razorpay_payment_id);
        $this->assertNotNull($fresh->razorpay_payment_id);
        $this->assertSame('advance_paid', $fresh->payment_status);
        $this->assertEquals(60.0, (float) $fresh->advance_amount);
        $this->assertSame('upi', $fresh->balance_payment_method);
    }

    public function test_balance_payment_method_can_be_cleared_and_rejects_unknown_values(): void
    {
        $this->actingAsToken($this->superadmin());
        $appointment = $this->advancePaidOnlineAppointment();

        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['balance_payment_method' => 'razorpay'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('balance_payment_method');

        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['balance_payment_method' => 'cash'])->assertOk();
        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['balance_payment_method' => null])
            ->assertOk()
            ->assertJsonPath('data.balance_payment_method', null);
    }

    public function test_online_appointment_accepts_unpaid_payment_status(): void
    {
        $this->actingAsToken($this->superadmin());
        $appointment = $this->advancePaidOnlineAppointment();

        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['payment_status' => 'unpaid'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'unpaid');
    }

    public function test_balance_payment_method_is_not_accepted_on_offline_appointments(): void
    {
        $this->actingAsToken($this->superadmin());
        $offline = Appointment::factory()->offline()->create(['status' => Appointment::STATUS_COMPLETED]);

        $this->patchJson("/api/admin/appointments/{$offline->id}", ['balance_payment_method' => 'upi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('balance_payment_method');

        $this->getJson("/api/admin/appointments/{$offline->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.balance_payment_method');
    }
}
