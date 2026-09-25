<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Stylist;
use App\Support\BookingAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingSlotsController extends Controller
{
    /**
     * The bookable start times for one service on one date — with a chosen
     * professional, or (no `stylist_id`) with anyone who offers it. Computed by
     * the same rules the appointment endpoint enforces, so the booking page
     * only ever shows times the API will accept. Read-only and public; it
     * exposes start/end times only, never anyone's booking details.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')->where('status', true)],
            'date' => ['required', 'date_format:Y-m-d'],
            'stylist_id' => ['nullable', 'integer', Rule::exists('stylists', 'id')->where('status', true)],
        ]);

        $service = Service::findOrFail($validated['service_id']);
        $stylist = ! empty($validated['stylist_id']) ? Stylist::find($validated['stylist_id']) : null;

        if ($stylist && ! $stylist->services()->where('services.id', $service->id)->exists()) {
            return response()->json([
                'message' => 'That professional does not offer this service.',
                'errors' => ['stylist_id' => ['That professional does not offer this service.']],
            ], 422);
        }

        return response()->json([
            'data' => BookingAvailability::slots($service, $stylist, $validated['date']),
        ]);
    }
}
