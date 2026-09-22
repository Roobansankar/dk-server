<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Support\AppointmentSlots;
use App\Support\Razorpay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Razorpay Standard Checkout for the online booking "Confirmation Fee" step
 * (TEST Mode). Two calls: `order()` opens (or resumes) a Razorpay Order for
 * the appointment's server-computed advance amount, `verify()` checks the
 * Checkout callback's signature and — only then — confirms the appointment
 * and locks its slot, reusing the same conflict check as the admin "confirm"
 * action (App\Support\AppointmentSlots).
 *
 * A pending, unpaid appointment already exists by the time either endpoint is
 * called (created by Public\AppointmentController::store); nothing here ever
 * creates a second one. Both endpoints only accept a `source = online`
 * appointment — an admin-created (offline) booking has no fee-payment step.
 */
class PaymentController extends Controller
{
    public function order(Request $request, Appointment $appointment): JsonResponse
    {
        $this->guardOnlineAppointment($appointment);

        if ($appointment->status !== Appointment::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'appointment' => "This appointment is {$appointment->status} and can no longer be paid for.",
            ]);
        }

        if ($appointment->payment_status !== Appointment::PAYMENT_UNPAID) {
            throw ValidationException::withMessages([
                'appointment' => 'This appointment has already been paid for.',
            ]);
        }

        // The fee is whatever was snapshotted onto the appointment at booking
        // time (Service::advance_amount at that moment) — never a value the
        // client supplies.
        $amountInr = (float) ($appointment->advance_amount ?? 0);
        if ($amountInr <= 0) {
            throw ValidationException::withMessages([
                'appointment' => 'This booking does not require a confirmation fee.',
            ]);
        }
        $amountPaise = (int) round($amountInr * 100);

        // Retry-safe: reuse the existing order instead of minting a new one
        // every time the customer reopens Checkout (closed modal, dropped
        // connection, etc).
        if (! $appointment->razorpay_order_id) {
            try {
                $order = Razorpay::createOrder($amountPaise, $appointment->reference, [
                    'appointment_reference' => $appointment->reference,
                ]);
            } catch (RuntimeException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 502);
            }

            $appointment->razorpay_order_id = $order['id'];
            $appointment->save();
        }

        return response()->json([
            'data' => [
                'key' => Razorpay::keyId(),
                'order_id' => $appointment->razorpay_order_id,
                'amount' => $amountPaise,
                'currency' => 'INR',
                'name' => 'DK StyleHub',
                'description' => $appointment->service_name ?: 'Appointment confirmation fee',
                'appointment' => [
                    'id' => $appointment->id,
                    'reference' => $appointment->reference,
                ],
                'prefill' => [
                    'name' => $appointment->customer_name,
                    'contact' => $appointment->phone,
                ],
            ],
        ]);
    }

    public function verify(Request $request, Appointment $appointment): JsonResponse|AppointmentResource
    {
        $this->guardOnlineAppointment($appointment);

        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        if (! $appointment->razorpay_order_id || $appointment->razorpay_order_id !== $data['razorpay_order_id']) {
            throw ValidationException::withMessages([
                'razorpay_order_id' => 'This payment does not match this appointment.',
            ]);
        }

        // Idempotent retry: the same successful payment verified twice (double
        // click, replayed callback) is a no-op success, not an error.
        if ($appointment->status === Appointment::STATUS_CONFIRMED
            && $appointment->payment_status !== Appointment::PAYMENT_UNPAID
        ) {
            if ($appointment->razorpay_payment_id === $data['razorpay_payment_id']) {
                return (new AppointmentResource($appointment->load(['service', 'serviceCategory', 'stylist'])))
                    ->additional(['message' => 'Payment already verified. Your appointment is confirmed.']);
            }

            throw ValidationException::withMessages([
                'appointment' => 'This appointment has already been paid for.',
            ]);
        }

        if (! Razorpay::verifySignature($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature'])) {
            throw ValidationException::withMessages([
                'razorpay_signature' => 'Payment verification failed. Please try again.',
            ]);
        }

        $confirmed = DB::transaction(function () use ($appointment, $data) {
            // Day lock, then this row — see lockDayThenAppointment()'s
            // docblock for why the order matters (avoids a lock-order
            // inversion deadlock between two concurrent verify() calls for
            // different appointments on the same stylist/day).
            $locked = AppointmentSlots::lockDayThenAppointment(
                $appointment->id,
                $appointment->stylist_id,
                $appointment->appointment_date,
            );

            if ($locked->status !== Appointment::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'appointment' => "This appointment is {$locked->status} and can no longer be confirmed.",
                ]);
            }

            $conflict = AppointmentSlots::findConflict(
                $locked->stylist_id,
                $locked->appointment_date,
                $locked->appointment_time?->format('H:i'),
                $locked->duration_minutes,
                $locked->id,
                AppointmentSlots::ONLINE_BUFFER_MINUTES,
            );

            if ($conflict) {
                throw ValidationException::withMessages([
                    'appointment_time' => AppointmentSlots::describeConflict($conflict)
                        .' Your payment was received — the studio will contact you to reschedule.',
                ]);
            }

            $locked->status = Appointment::STATUS_CONFIRMED;
            $locked->payment_status = Appointment::PAYMENT_ADVANCE_PAID;
            $locked->razorpay_payment_id = $data['razorpay_payment_id'];
            $locked->save();

            return $locked;
        });

        return (new AppointmentResource($confirmed->load(['service', 'serviceCategory', 'stylist'])))
            ->additional(['message' => 'Payment verified. Your appointment is confirmed.']);
    }

    /** Offline (admin-created) appointments have no fee-payment step. */
    private function guardOnlineAppointment(Appointment $appointment): void
    {
        abort_unless($appointment->source === Appointment::SOURCE_ONLINE, 404);
    }
}
