<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class PaymentReportTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    private function completedAppointment(array $attrs = []): Appointment
    {
        $category = ServiceCategory::factory()->female()->create();
        $service = Service::factory()->forCategory($category)->create([
            'price' => 2000, 'advance_percentage' => 25,
        ]);

        return Appointment::factory()->forService($service)->create(array_merge([
            'status' => Appointment::STATUS_COMPLETED,
            'payment_status' => Appointment::PAYMENT_ADVANCE_PAID,
            'appointment_date' => '2026-03-15',
        ], $attrs));
    }

    public function test_payments_endpoint_only_returns_completed_appointments(): void
    {
        $this->actingAsToken($this->superadmin());
        $this->completedAppointment();
        Appointment::factory()->create(['status' => Appointment::STATUS_PENDING]);
        Appointment::factory()->create(['status' => Appointment::STATUS_CANCELLED]);

        $this->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'completed');
    }

    public function test_payment_summary_totals_are_correct(): void
    {
        $this->actingAsToken($this->superadmin());
        // price 2000, advance 25% -> advance 500
        $this->completedAppointment(['payment_status' => Appointment::PAYMENT_ADVANCE_PAID]); // received 500, remaining 1500
        $this->completedAppointment(['payment_status' => Appointment::PAYMENT_PAID]);         // received 2000, remaining 0
        $this->completedAppointment(['payment_status' => Appointment::PAYMENT_UNPAID]);       // received 0, remaining 2000

        $summary = $this->getJson('/api/admin/payments')->assertOk()->json('summary');

        $this->assertSame(3, $summary['completed_appointments']);
        $this->assertEqualsWithDelta(6000, $summary['total_service_value'], 0.01);
        $this->assertEqualsWithDelta(1000, $summary['total_advance_received'], 0.01); // 500 + 500 (unpaid excluded)
        $this->assertEqualsWithDelta(2500, $summary['total_collected'], 0.01);        // 500 + 2000 + 0
        $this->assertEqualsWithDelta(3500, $summary['total_remaining'], 0.01);        // 1500 + 0 + 2000
    }

    public function test_payments_can_be_filtered_by_date_range(): void
    {
        $this->actingAsToken($this->superadmin());
        $this->completedAppointment(['appointment_date' => '2026-01-05']);
        $this->completedAppointment(['appointment_date' => '2026-06-20']);

        $this->getJson('/api/admin/payments?date_from=2026-06-01&date_to=2026-06-30')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_export_returns_a_pdf(): void
    {
        $this->actingAsToken($this->superadmin());
        $this->completedAppointment();

        $response = $this->get('/api/admin/payments/export', [
            'Authorization' => 'Bearer '.$this->superadmin()->createToken('t')->plainTextToken,
        ]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    public function test_payment_endpoints_require_the_right_permissions(): void
    {
        // no permissions at all
        $this->actingAsToken($this->userWith(['appointments.view']));
        $this->getJson('/api/admin/payments')->assertForbidden();
        $this->getJson('/api/admin/payments/export')->assertForbidden();

        // payments.view can read but not export
        $this->actingAsToken($this->userWith(['payments.view']));
        $this->getJson('/api/admin/payments')->assertOk();
        $this->getJson('/api/admin/payments/export')->assertForbidden();

        // payments.export can export
        $this->actingAsToken($this->userWith(['payments.view', 'payments.export']));
        $this->getJson('/api/admin/payments/export')->assertOk();
    }

    public function test_guests_cannot_touch_payment_endpoints(): void
    {
        $this->getJson('/api/admin/payments')->assertUnauthorized();
        $this->getJson('/api/admin/payments/export')->assertUnauthorized();
    }
}
