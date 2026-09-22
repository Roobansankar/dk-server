<?php

namespace App\Http\Resources;

use App\Support\AppointmentSlots;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single occupied time window for a stylist on a given day — used only to
 * compute which "Preferred time" slots the public booking form can offer.
 * Deliberately minimal: no customer name, phone, service, or reference, so
 * the endpoint stays safe to expose without authentication.
 */
class BusySlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $start = $this->appointment_time?->format('H:i');

        return [
            'start' => $start,
            'end' => AppointmentSlots::endTime($start, $this->duration_minutes),
        ];
    }
}
