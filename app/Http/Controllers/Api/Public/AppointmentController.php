<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\BusySlotResource;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\Stylist;
use App\Support\AppointmentSlots;
use App\Support\BookingAvailability;
use App\Support\PayAtStore;
use App\Support\WhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $multi = $request->filled('service_ids');
        $ids = $multi
            ? collect($request->input('service_ids'))->map(fn ($id) => (int) $id)->unique()->values()
            : collect([$request->integer('service_id')]);
        $services = Service::with('category')->whereIn('id', $ids)->get()
            ->sortBy(fn ($s) => $ids->search((int) $s->id))->values();
        $service = $services->firstOrFail();
        $requestedStylist = $request->filled('stylist_id')
            ? Stylist::find($request->integer('stylist_id'))
            : null;
        $payAtStore = $request->wantsPayAtStore();

        [$appointment, $created] = DB::transaction(function () use ($request, $service, $services, $multi, $requestedStylist, $payAtStore) {
            $date = $request->date('appointment_date')->toDateString();
            $time = $request->input('appointment_time');

            // Pay at Store retry (double click, dropped response): the first
            // attempt already holds this exact slot, so resolving again would
            // only report a clash with itself. Under the professional's day
            // lock, hand back that same appointment instead — no second
            // booking, no second WhatsApp.
            if ($payAtStore) {
                AppointmentSlots::lockDay($requestedStylist->id, $date);

                $existing = PayAtStore::findRetry((string) $request->string('phone'), $requestedStylist->id, $date, $time, $services);
                if ($existing) {
                    return [$existing, false];
                }
            }

            // Decide who takes this booking while holding the professional's
            // whole-day lock, so a concurrent request for the same/overlapping
            // slot serialises here instead of racing past the check (see
            // AppointmentSlots::lockDay). With no professional chosen ("any"),
            // this assigns the first eligible one who is free. The FormRequest
            // ran the same rules unlocked, purely for a fast validation
            // message — this is the one that actually has to hold.
            // Multi-service: durations are summed into ONE combined slot.
            [$stylist, $error] = $multi
                ? BookingAvailability::resolveServices(
                    $services,
                    $requestedStylist,
                    $date,
                    $time,
                    lock: true,
                )
                : BookingAvailability::resolve(
                    $service,
                    $requestedStylist,
                    $date,
                    $time,
                    lock: true,
                );

            if (! $stylist) {
                throw ValidationException::withMessages(['appointment_time' => $error]);
            }

            $appointment = new Appointment([
                'customer_name' => $request->string('customer_name'),
                'phone' => $request->string('phone'),
                'gender' => $request->string('gender'),
                'source' => Appointment::SOURCE_ONLINE,
                'appointment_date' => $request->date('appointment_date'),
                'appointment_time' => $request->string('appointment_time'),
                'status' => Appointment::STATUS_PENDING,
            ]);
            if ($multi) {
                $appointment->applyServicesSnapshot($services, $stylist);
            } else {
                $appointment->applyServiceSnapshot($service, $stylist);
            }
            $appointment->applyStylistSnapshot($stylist);

            if ($payAtStore) {
                // The full price just snapshotted (this professional's own
                // price where set) against the limit saved right now — nothing
                // the client sent about price or eligibility is consulted.
                if (! PayAtStore::allows($appointment->service_price)) {
                    throw ValidationException::withMessages([
                        'payment_option' => 'Pay at Store isn’t available for this booking. Please continue with the advance payment.',
                    ]);
                }

                // Confirmed now (the slot is checked and locked above, so a
                // confirmed row is what reserves it), with the whole bill due
                // at the studio.
                $appointment->status = Appointment::STATUS_CONFIRMED;
                $appointment->payment_status = Appointment::PAYMENT_UNPAID;
                $appointment->payment_method = Appointment::PAYMENT_METHOD_PAY_AT_STORE;
            }

            $appointment->save();

            return [$appointment, true];
        });

        if ($payAtStore) {
            if ($created) {
                $this->notifyPayAtStoreBooked($appointment);
            }

            return (new AppointmentResource($appointment))
                ->additional(['message' => 'Your appointment is confirmed. Please pay at the studio when you arrive.'])
                ->response()
                ->setStatusCode($created ? 201 : 200);
        }

        return (new AppointmentResource($appointment))
            ->additional(['message' => 'Your appointment request has been received. The studio will confirm it shortly.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Same WhatsApp pair as an admin-created confirmed + unpaid booking: the
     * customer's just-booked message (`appointment_booked2`, no amount — it
     * never claims a payment) plus the owner's new-booking alert. Best-effort:
     * a WhatsApp failure never fails or undoes the booking.
     */
    private function notifyPayAtStoreBooked(Appointment $appointment): void
    {
        try {
            DB::afterCommit(function () use ($appointment) {
                $fresh = $appointment->fresh();
                WhatsApp::sendBookingBooked($fresh);
                WhatsApp::sendOwnerBookingAlert($fresh);
            });
        } catch (\Throwable $e) {
            Log::warning('WhatsApp after Pay at Store booking skipped: '.$e->getMessage());
        }
    }
}
