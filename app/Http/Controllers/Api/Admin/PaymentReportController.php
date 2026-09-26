<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Support\AppointmentFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class PaymentReportController extends Controller
{
    /** Paid or completed appointments with their payment record + running totals. */
    public function index(Request $request)
    {
        $request->validate(AppointmentFilters::rules());

        $base = AppointmentFilters::apply(Appointment::query()->paidOrCompleted(), $request);

        $rows = (clone $base)->get();

        $appointments = (clone $base)
            ->orderByDesc('appointment_date')
            ->orderByDesc('appointment_time')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return AppointmentResource::collection($appointments)
            ->additional(['summary' => $this->summary($rows)]);
    }

    /**
     * Download the current (filtered) payment report as an .xlsx workbook —
     * same OpenSpout writer + download response as the appointment export.
     */
    public function export(Request $request)
    {
        $request->validate(AppointmentFilters::rules());

        $rows = AppointmentFilters::apply(Appointment::query()->paidOrCompleted(), $request)
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->get();
        $summary = $this->summary($rows);

        $path = tempnam(sys_get_temp_dir(), 'payments_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([
            'Reference', 'Date', 'Customer', 'Phone', 'Service', 'Category', 'Gender',
            'Price', 'Advance %', 'Advance', 'Remaining', 'Payment Status',
        ]));

        foreach ($rows as $r) {
            $writer->addRow(Row::fromValues([
                (string) $r->reference,
                optional($r->appointment_date)->toDateString() ?? '',
                (string) $r->customer_name,
                (string) $r->phone,
                (string) $r->service_name,
                (string) $r->category_name,
                ucfirst((string) $r->gender),
                (float) ($r->service_price ?? 0),
                (int) ($r->advance_percentage ?? 0),
                (float) ($r->advance_amount ?? 0),
                (float) $r->remaining_amount,
                ucwords(str_replace('_', ' ', (string) $r->payment_status)),
            ]));
        }

        $writer->addRow(Row::fromValues([
            'Totals ('.$summary['completed_appointments'].' completed)', '', '', '', '', '', '',
            $summary['total_service_value'], '', $summary['total_advance_received'], $summary['total_remaining'], '',
        ]));
        $writer->close();

        return response()->download($path, 'dk-stylehub-payments-'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** @param  Collection<int, Appointment>  $rows */
    private function summary($rows): array
    {
        return [
            // $rows is now "paid or completed" (see scopePaidOrCompleted) —
            // count only the actually-completed ones here so this stat keeps
            // its literal meaning instead of drifting to "rows shown".
            'completed_appointments' => $rows->where('status', Appointment::STATUS_COMPLETED)->count(),
            'total_service_value' => round($rows->sum(fn ($a) => (float) ($a->service_price ?? 0)), 2),
            'total_advance_received' => round($rows
                ->where('payment_status', '!=', Appointment::PAYMENT_UNPAID)
                ->sum(fn ($a) => (float) ($a->advance_amount ?? 0)), 2),
            'total_collected' => round($rows->sum(fn ($a) => $a->amount_received), 2),
            'total_remaining' => round($rows->sum(fn ($a) => $a->remaining_amount), 2),
        ];
    }
}
