<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\SiteSetting;
use App\Support\AppointmentFilters;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

    public function export(Request $request)
    {
        $request->validate(AppointmentFilters::rules());

        $rows = AppointmentFilters::apply(Appointment::query()->paidOrCompleted(), $request)
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->get();

        $pdf = Pdf::loadView('pdf.payment-report', [
            'rows' => $rows,
            'summary' => $this->summary($rows),
            'filters' => AppointmentFilters::describe($request),
            'salonName' => SiteSetting::allValues()->get('salon_name', 'DK StyleHub'),
            'generatedAt' => now(),
            'dateFrom' => $request->input('date_from'),
            'dateTo' => $request->input('date_to'),
        ])->setPaper('a4');

        $name = 'dk-stylehub-payments-'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($name);
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
