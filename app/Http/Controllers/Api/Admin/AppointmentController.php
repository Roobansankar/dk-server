<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOfflineAppointmentRequest;
use App\Http\Requests\Admin\UpdateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\Stylist;
use App\Support\AppointmentFilters;
use App\Support\AppointmentSlots;
use App\Support\AppointmentsWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(AppointmentFilters::rules() + [
            'sort' => ['sometimes', 'in:appointment_date,created_at'],
            'direction' => ['sometimes', 'in:asc,desc'],
        ]);

        $sort = $request->string('sort', 'appointment_date')->value();
        $direction = $request->string('direction', 'desc')->value();

        $appointments = AppointmentFilters::apply(Appointment::query(), $request)
            ->when(
                $sort === 'created_at',
                fn ($q) => $q->orderBy('created_at', $direction),
                fn ($q) => $q->orderBy('appointment_date', $direction)->orderBy('appointment_time', $direction),
            )
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return AppointmentResource::collection($appointments);
    }

    /**
     * Download the current (optionally filtered) appointment list as an .xlsx
     * workbook in the shared admin format — the same columns the
     * `appointments:import` command reads back.
     */
    public function export(Request $request)
    {
        $request->validate(AppointmentFilters::rules());

        $query = AppointmentFilters::apply(Appointment::query(), $request)
            ->orderBy('appointment_date', 'desc')
            ->orderBy('appointment_time', 'desc');

        $path = tempnam(sys_get_temp_dir(), 'appts_').'.xlsx';
        AppointmentsWorkbook::write($query->lazy(), $path);

        return response()->download($path, 'appointments-'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Create an appointment on behalf of the salon (walk-in / phone / in-person).
     * Marked source = offline; the service + stylist configuration is snapshotted
     * exactly like a public booking, so history and payment reports stay accurate.
     *
     * When it is created already-confirmed (the default), the same slot-locking
     * rules as an online confirmation apply: an overlapping confirmed appointment
     * for the same stylist is rejected.
     */
    public function store(StoreOfflineAppointmentRequest $request)
    {
        $service = Service::with('category')->findOrFail($request->integer('service_id'));
        $stylist = $request->filled('stylist_id') ? Stylist::find($request->integer('stylist_id')) : null;
        $status = $request->input('status', Appointment::STATUS_CONFIRMED);

        $appointment = DB::transaction(function () use ($request, $service, $stylist, $status) {
            if ($status === Appointment::STATUS_CONFIRMED) {
                $this->assertSlotIsFree(
                    $stylist?->id,
                    $request->date('appointment_date'),
                    $request->string('appointment_time')->value(),
                    $service->duration_minutes,
                );
            }

            $appointment = new Appointment([
                'customer_name' => $request->string('customer_name'),
                'phone' => $request->string('phone'),
                'gender' => $request->string('gender'),
                'source' => Appointment::SOURCE_OFFLINE,
                'appointment_date' => $request->date('appointment_date'),
                'appointment_time' => $request->string('appointment_time'),
                'message' => $request->input('message'),
                'notes' => $request->input('notes'),
                'status' => $status,
                'payment_status' => $request->input('payment_status', Appointment::PAYMENT_UNPAID),
                'payment_method' => $request->input('payment_method'),
            ]);
            $appointment->applyServiceSnapshot($service, $stylist);
            $appointment->applyStylistSnapshot($stylist);
            $appointment->save();

            return $appointment;
        });

        return (new AppointmentResource($appointment))
            ->additional(['message' => 'Offline appointment created.'])
            ->response()->setStatusCode(201);
    }

    public function show(Appointment $appointment)
    {
        return new AppointmentResource($appointment->load(['service', 'serviceCategory', 'stylist']));
    }

    /**
     * Permanently remove an appointment (any status). Route-gated by
     * `permission:appointments.manage`; the policy check here is a second,
     * explicit server-side guard. Nothing about slot locking changes — a
     * deleted row simply stops being a conflict for future confirmations, the
     * same as a cancelled one.
     */
    public function destroy(Appointment $appointment)
    {
        $this->authorize('delete', $appointment);

        $appointment->delete();

        return response()->noContent();
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment)
    {
        $data = $request->validated();

        $targetStatus = $data['status'] ?? $appointment->status;
        $rescheduling = array_key_exists('appointment_date', $data) || array_key_exists('appointment_time', $data);
        $needsSlotCheck = $targetStatus === Appointment::STATUS_CONFIRMED
            && ($appointment->status !== Appointment::STATUS_CONFIRMED || $rescheduling);

        if (! $needsSlotCheck) {
            $appointment->update($data);

            return new AppointmentResource($appointment->fresh());
        }

        // Date/time/stylist for the lock come from the pre-transaction model —
        // none of them change concurrently outside this same request (stylist
        // isn't editable here; date/time are read from the incoming, already-
        // validated $data) — so it's safe to resolve them before locking.
        $date = $data['appointment_date'] ?? $appointment->appointment_date;
        $time = $data['appointment_time'] ?? $appointment->appointment_time?->format('H:i');

        $fresh = DB::transaction(function () use ($appointment, $data, $date, $time) {
            // Day lock, then this row — see
            // AppointmentSlots::lockDayThenAppointment()'s docblock for why
            // the order matters (avoids a lock-order inversion deadlock
            // between two concurrent admin actions on the same stylist/day).
            $locked = AppointmentSlots::lockDayThenAppointment($appointment->id, $appointment->stylist_id, $date);

            $conflict = AppointmentSlots::findConflict(
                $locked->stylist_id,
                $date,
                $time,
                $locked->duration_minutes,
                $locked->id,
            );

            if ($conflict) {
                throw ValidationException::withMessages([
                    'appointment_time' => AppointmentSlots::describeConflict($conflict),
                ]);
            }

            $locked->update($data);

            return $locked;
        });

        return new AppointmentResource($fresh->fresh());
    }

    /**
     * Confirm / accept a pending appointment after the advance has been verified
     * offline. Runs the full guarded flow:
     *   1. resolve the service duration and compute start/end
     *   2. lock the stylist's day, then this appointment's own row (in that
     *      order — see AppointmentSlots::lockDayThenAppointment())
     *   3. re-check it is still confirmable on the freshly locked row
     *   4. re-check for confirmed conflicts
     *   5. on conflict → 422 with a clear message
     *   6. otherwise flip to confirmed (optionally recording payment) atomically
     */
    public function confirm(Request $request, Appointment $appointment)
    {
        $data = $request->validate([
            'payment_status' => ['sometimes', Rule::in(Appointment::PAYMENT_STATUSES)],
        ]);

        if (! in_array($appointment->status, [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED], true)) {
            throw ValidationException::withMessages([
                'status' => "This appointment is {$appointment->status} and can no longer be confirmed.",
            ]);
        }

        $duration = $appointment->duration_minutes ?: optional($appointment->service()->withTrashed()->first())->duration_minutes;

        if (! $appointment->appointment_date || ! $appointment->appointment_time || ! $duration) {
            throw ValidationException::withMessages([
                'appointment_time' => 'This appointment is missing a date, time or service duration, so its slot cannot be locked. Edit it first.',
            ]);
        }

        $confirmed = DB::transaction(function () use ($appointment, $data, $duration) {
            // Day lock, then this row — see
            // AppointmentSlots::lockDayThenAppointment()'s docblock for why
            // the order matters (avoids a lock-order inversion deadlock
            // between two concurrent admin confirmations on the same
            // stylist/day, or an admin confirm racing a customer's payment
            // verification for a different appointment on that same day).
            $locked = AppointmentSlots::lockDayThenAppointment(
                $appointment->id,
                $appointment->stylist_id,
                $appointment->appointment_date,
            );

            // Re-checked on the freshly locked row: status may have changed
            // between the pre-lock read above and acquiring this lock.
            if (! in_array($locked->status, [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED], true)) {
                throw ValidationException::withMessages([
                    'status' => "This appointment is {$locked->status} and can no longer be confirmed.",
                ]);
            }

            $conflict = AppointmentSlots::findConflict(
                $locked->stylist_id,
                $locked->appointment_date,
                $locked->appointment_time->format('H:i'),
                (int) $duration,
                $locked->id,
            );

            if ($conflict) {
                throw ValidationException::withMessages([
                    'appointment_time' => AppointmentSlots::describeConflict($conflict),
                ]);
            }

            $locked->status = Appointment::STATUS_CONFIRMED;
            if (! $locked->duration_minutes) {
                $locked->duration_minutes = (int) $duration;
            }
            if (! empty($data['payment_status'])) {
                $locked->payment_status = $data['payment_status'];
            }
            $locked->save();

            return $locked;
        });

        return (new AppointmentResource($confirmed->load(['service', 'serviceCategory', 'stylist'])))
            ->additional(['message' => 'Appointment confirmed. The time slot is now blocked for this stylist.']);
    }

    /**
     * Reject a candidate slot if it overlaps a confirmed appointment for the
     * same stylist. Must be called inside a transaction after lockDay().
     */
    private function assertSlotIsFree(
        ?int $stylistId,
        $date,
        ?string $time,
        ?int $durationMinutes,
        ?int $ignoreId = null,
    ): void {
        AppointmentSlots::lockDay($stylistId, $date);

        $conflict = AppointmentSlots::findConflict($stylistId, $date, $time, $durationMinutes, $ignoreId);

        if ($conflict) {
            throw ValidationException::withMessages([
                'appointment_time' => AppointmentSlots::describeConflict($conflict),
            ]);
        }
    }
}
