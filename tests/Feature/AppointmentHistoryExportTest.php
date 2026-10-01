<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Support\AppointmentsWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

/**
 * History → Export Excel downloads ONLY the filtered rows: the same
 * AppointmentFilters the list uses (date range, status, search, …) apply to
 * the workbook. No filter → everything.
 */
class AppointmentHistoryExportTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    private function make(string $date, string $status, string $name): Appointment
    {
        return Appointment::factory()->create([
            'appointment_date' => $date,
            'status' => $status,
            'customer_name' => $name,
        ]);
    }

    /** @return list<array<string, mixed>> data rows (headers stripped). */
    private function exportedRows(array $params): array
    {
        $response = $this->get('/api/admin/appointments/export?'.http_build_query($params));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'export_test_').'.xlsx';
        file_put_contents($path, $response->streamedContent() ?? $response->getContent());

        try {
            return AppointmentsWorkbook::read($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_export_respects_the_from_to_date_range(): void
    {
        $this->actingAsToken($this->superadmin());
        $this->make('2026-09-05', Appointment::STATUS_COMPLETED, 'In Range One');
        $this->make('2026-09-08', Appointment::STATUS_COMPLETED, 'In Range Two');
        $this->make('2026-08-20', Appointment::STATUS_COMPLETED, 'Out Of Range');

        $rows = $this->exportedRows(['date_from' => '2026-09-01', 'date_to' => '2026-09-10']);

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            ['In Range One', 'In Range Two'],
            array_column($rows, 'customer_name')
        );
    }

    public function test_export_respects_status_and_search_filters(): void
    {
        $this->actingAsToken($this->superadmin());
        $this->make('2026-09-05', Appointment::STATUS_COMPLETED, 'Priya Export');
        $this->make('2026-09-06', Appointment::STATUS_CONFIRMED, 'Priya Other');
        $this->make('2026-09-07', Appointment::STATUS_COMPLETED, 'Someone Else');

        $rows = $this->exportedRows(['status' => 'completed', 'search' => 'Priya Export']);

        $this->assertCount(1, $rows);
        $this->assertSame('Priya Export', $rows[0]['customer_name']);
    }

    public function test_export_without_filters_downloads_everything(): void
    {
        $this->actingAsToken($this->superadmin());
        $this->make('2026-09-05', Appointment::STATUS_COMPLETED, 'First Export');
        $this->make('2026-07-01', Appointment::STATUS_PENDING, 'Second Export');

        $this->assertCount(2, $this->exportedRows([]));
    }
}
