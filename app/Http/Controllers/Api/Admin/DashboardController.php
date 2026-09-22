<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\PricingPlan;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $byStatus = Appointment::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $statusDistribution = collect(Appointment::STATUSES)
            ->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)]);

        return response()->json([
            'data' => [
                'stats' => [
                    'appointment_requests' => (int) $byStatus->sum(),
                    'confirmed_appointments' => (int) ($byStatus['confirmed'] ?? 0),
                    'completed_appointments' => (int) ($byStatus['completed'] ?? 0),
                    'cancelled_appointments' => (int) ($byStatus['cancelled'] ?? 0),
                    'services_offered' => Service::where('status', true)->count(),
                    'active_pricing_plans' => PricingPlan::where('status', true)->count(),
                    'service_categories' => ServiceCategory::where('status', true)->count(),
                ],
                'status_distribution' => $statusDistribution,
                'appointments_over_time' => $this->appointmentsOverTime(),
                'gender_distribution' => $this->genderDistribution(),
                'popular_services' => $this->popularServices(),
                'category_performance' => $this->categoryPerformance(),
                'recent_appointments' => AppointmentResource::collection(
                    Appointment::latest()->limit(8)->get()
                ),
            ],
        ]);
    }

    private function appointmentsOverTime(): array
    {
        $start = Carbon::today()->subDays(29);

        $counts = Appointment::query()
            ->where('created_at', '>=', $start)
            ->get()
            ->groupBy(fn ($a) => $a->created_at->toDateString())
            ->map->count();

        return collect(range(0, 29))->map(function ($i) use ($start, $counts) {
            $date = $start->copy()->addDays($i)->toDateString();

            return ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
        })->all();
    }

    private function genderDistribution()
    {
        $raw = Appointment::query()
            ->select('gender', DB::raw('count(*) as total'))
            ->groupBy('gender')
            ->pluck('total', 'gender');

        return collect(['male', 'female', 'unisex'])
            ->mapWithKeys(fn ($g) => [$g => (int) ($raw[$g] ?? 0)]);
    }

    private function popularServices(): array
    {
        return Appointment::query()
            ->select('service_name', DB::raw('count(*) as total'))
            ->whereNotNull('service_name')
            ->groupBy('service_name')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['name' => $row->service_name, 'count' => (int) $row->total])
            ->all();
    }

    private function categoryPerformance(): array
    {
        return Appointment::query()
            ->select('category_name', DB::raw('count(*) as total'))
            ->whereNotNull('category_name')
            ->groupBy('category_name')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['name' => $row->category_name, 'count' => (int) $row->total])
            ->all();
    }
}
