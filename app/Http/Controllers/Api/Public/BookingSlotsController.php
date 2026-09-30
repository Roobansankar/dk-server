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
     * The bookable start times for one service (or several — durations are
     * added and ONE combined slot is shown) on one date — with a chosen
     * professional, or (no `stylist_id`) with anyone who offers it all.
     * Computed by the same rules the appointment endpoint enforces, so the
     * booking page only ever shows times the API will accept. Read-only and
     * public; it exposes start/end times only, never anyone's booking details.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required_without:service_ids', 'nullable', 'integer', Rule::exists('services', 'id')->where('status', true)],
            'service_ids' => ['required_without:service_id', 'nullable', 'array', 'min:1', 'max:10'],
            'service_ids.*' => ['integer', Rule::exists('services', 'id')->where('status', true)],
            'date' => ['required', 'date_format:Y-m-d'],
            'stylist_id' => ['nullable', 'integer', Rule::exists('stylists', 'id')->where('status', true)],
        ]);

        $ids = ! empty($validated['service_ids'])
            ? collect($validated['service_ids'])->map(fn ($id) => (int) $id)->unique()->values()
            : collect([(int) $validated['service_id']]);

        $services = Service::whereIn('id', $ids)->get()->sortBy(fn ($s) => $ids->search((int) $s->id))->values();

        if ($services->count() !== $ids->count()) {
            return response()->json([
                'message' => 'One of the selected services is no longer available.',
                'errors' => ['service_ids' => ['One of the selected services is no longer available.']],
            ], 422);
        }

        $stylist = ! empty($validated['stylist_id']) ? Stylist::find($validated['stylist_id']) : null;

        if ($stylist) {
            $offered = $stylist->services()->whereIn('services.id', $ids->all())->count();
            if ($offered !== $ids->count()) {
                return response()->json([
                    'message' => 'That professional does not offer all the selected services.',
                    'errors' => ['stylist_id' => ['That professional does not offer all the selected services.']],
                ], 422);
            }
        }

        return response()->json([
            'data' => BookingAvailability::slotsForServices($services, $stylist, $validated['date']),
        ]);
    }
}
