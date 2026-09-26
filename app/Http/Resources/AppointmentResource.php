<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Support\AppointmentSlots;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'customer_name' => $this->customer_name,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'source' => $this->source,
            'category_id' => $this->service_category_id,
            'service_id' => $this->service_id,
            'stylist_id' => $this->stylist_id,
            'category_name' => $this->category_name,
            'service_name' => $this->service_name,
            'stylist_name' => $this->stylist_name,
            'duration_minutes' => $this->duration_minutes,
            'service_price' => $this->service_price !== null ? (float) $this->service_price : null,
            'advance_percentage' => $this->advance_percentage,
            'advance_amount' => $this->advance_amount !== null ? (float) $this->advance_amount : null,
            'amount_received' => round($this->amount_received, 2),
            'remaining_amount' => $this->remaining_amount,
            'payment_status' => $this->payment_status,
            // offline (staff-recorded) payments only, admin-only
            'payment_method' => $this->when(
                $this->source === Appointment::SOURCE_OFFLINE && $request->user()?->can('appointments.view'),
                $this->payment_method,
            ),
            // online bookings only: how the balance was paid at the salon
            // (the advance is the Razorpay payment), admin-only
            'balance_payment_method' => $this->when(
                $this->source !== Appointment::SOURCE_OFFLINE && $request->user()?->can('appointments.view'),
                $this->balance_payment_method,
            ),
            'appointment_date' => $this->appointment_date?->toDateString(),
            'appointment_time' => $this->appointment_time
                ? Carbon::parse($this->appointment_time)->format('H:i')
                : null,
            // Derived: start + service duration. The confirmed slot that gets
            // locked runs from appointment_time to this value.
            'appointment_end_time' => AppointmentSlots::endTime(
                $this->appointment_time ? Carbon::parse($this->appointment_time)->format('H:i') : null,
                $this->duration_minutes,
            ),
            'message' => $this->message,
            // internal notes are admin-only
            'notes' => $this->when($request->user()?->can('appointments.view'), $this->notes),
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
