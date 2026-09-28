<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\StylistResource;
use App\Models\Stylist;
use App\Support\BookingAvailability;
use Illuminate\Support\Carbon;

class StylistController extends Controller
{
    /** How far ahead calendar dates are sent — comfortably past the 60-day booking window. */
    public const DATE_HOURS_DAYS_AHEAD = 120;

    public function index()
    {
        $today = Carbon::now(BookingAvailability::TZ);

        return StylistResource::collection(
            Stylist::query()
                ->active()
                ->ordered()
                // Only active services count towards what a professional offers.
                ->with([
                    'services' => fn ($q) => $q->where('services.status', true)->select('services.id'),
                    // The upcoming dates each has hours of their own for — combined
                    // with weeklyHours/dateClosures client-side to decide, per date,
                    // whether they're actually available.
                    'dateHours' => fn ($q) => $q
                        ->whereDate('date', '>=', $today->toDateString())
                        ->whereDate('date', '<=', $today->copy()->addDays(self::DATE_HOURS_DAYS_AHEAD)->toDateString()),
                    'dateClosures' => fn ($q) => $q
                        ->whereDate('date', '>=', $today->toDateString())
                        ->whereDate('date', '<=', $today->copy()->addDays(self::DATE_HOURS_DAYS_AHEAD)->toDateString()),
                    'weeklyHours',
                ])
                ->get()
        );
    }
}
