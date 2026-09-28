<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\StudioHoliday;
use App\Support\BookingAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class StudioHolidayController extends Controller
{
    /**
     * Upcoming studio-wide closed days (date + name) — the booking page uses
     * these to grey out dates the whole studio is closed, before even asking
     * for per-professional slots. Read-only and public.
     */
    public function index(): JsonResponse
    {
        $today = Carbon::now(BookingAvailability::TZ)->toDateString();

        $holidays = StudioHoliday::query()
            ->whereDate('date', '>=', $today)
            ->orderBy('date')
            ->limit(400)
            ->get()
            ->map(fn (StudioHoliday $holiday) => [
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
            ])
            ->values();

        return response()->json(['data' => $holidays]);
    }
}
