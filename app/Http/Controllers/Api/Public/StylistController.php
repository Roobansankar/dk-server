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
    private const DATE_HOURS_DAYS_AHEAD = 120;

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
                    'workHours',
                    // Upcoming days off / custom hours, so the booking page can grey days out.
                    'dateHours' => fn ($q) => $q
                        ->whereDate('date', '>=', $today->toDateString())
                        ->whereDate('date', '<=', $today->copy()->addDays(self::DATE_HOURS_DAYS_AHEAD)->toDateString()),
                ])
                ->get()
        );
    }
}
