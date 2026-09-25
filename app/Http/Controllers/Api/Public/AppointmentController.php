<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\BusySlotResource;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\Stylist;
use App\Support\BookingAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    /**
     * Occupied time windows for one stylist on one date — the same rule the
     * booking form's own submission is checked against server-side (see
     * StoreAppointmentRequest + App\Support\AppointmentSlots): a CONFIRMED
     * appointment locks its full service duration for its stylist. Read-only,
     * unauthenticated, and deliberately narrow (start/end only — no customer
     * data) so the public "Preferred time" picker can grey out slots that
     * would conflict, without exposing anyone else's booking details.
     *
     * "Any available stylist" bookings are intentionally not range-checked
     * here either, matching AppointmentSlots — there is no per-chair capacity
     * model, so the frontend simply skips this call when no stylist is chosen.
     */
    public function busy(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'stylist_id' => ['required', 'integer', 'exists:stylists,id'],
            'date' => ['required', 'date'],
        ]);

        $appointments = Appointment::query()
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->where('stylist_id', $validated['stylist_id'])
            ->whereDate('appointment_date', $validated['date'])
            ->whereNotNull('appointment_time')
            ->whereNotNull('duration_minutes')
            ->get(['appointment_time', 'duration_minutes']);

        return BusySlotResource::collection($appointments);
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $service = Service::with('category')->findOrFail($request->integer('service_id'));
        $requestedStylist = $request->filled('stylist_id')
            ? Stylist::find($request->integer('stylist_id'))
            : null;
        // The route requires `auth:sanctum`, so `$request->user()` is always
        // present here. Ownership is derived solely from the authenticated
        // token — there is no `user_id` field anywhere in
        // StoreAppointmentRequest::rules(), so a crafted request body can
        // never claim someone else's account.
        $userId = $request->user()->id;

        $appointment = DB::transaction(function () use ($request, $service, $requestedStylist, $userId) {
            // Decide who takes this booking while holding the professional's
            // whole-day lock, so a concurrent request for the same/overlapping
            // slot serialises here instead of racing past the check (see
            // AppointmentSlots::lockDay). With no professional chosen ("any"),
            // this assigns the first eligible one who is free. The FormRequest
            // ran the same rules unlocked, purely for a fast validation
            // message — this is the one that actually has to hold.
            [$stylist, $error] = BookingAvailability::resolve(
                $service,
                $requestedStylist,
                $request->date('appointment_date')->toDateString(),
                $request->input('appointment_time'),
                lock: true,
            );

            if (! $stylist) {
                throw ValidationException::withMessages(['appointment_time' => $error]);
            }

            $appointment = new Appointment([
                'user_id' => $userId,
                'customer_name' => $request->string('customer_name'),
                'phone' => $request->string('phone'),
                'gender' => $request->string('gender'),
                'source' => Appointment::SOURCE_ONLINE,
                'appointment_date' => $request->date('appointment_date'),
                'appointment_time' => $request->string('appointment_time'),
                'status' => Appointment::STATUS_PENDING,
            ]);
            $appointment->applyServiceSnapshot($service);
            $appointment->applyStylistSnapshot($stylist);
            $appointment->save();

            return $appointment;
        });

        return (new AppointmentResource($appointment))
            ->additional(['message' => 'Your appointment request has been received. The studio will confirm it shortly.'])
            ->response()
            ->setStatusCode(201);
    }
}
