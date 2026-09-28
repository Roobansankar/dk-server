<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One calendar date the whole studio is closed (e.g. Diwali) — no
 * professional can be booked that day, whatever their own hours say.
 * Managed from Admin → Studio → Holidays. See BookingAvailability::windows().
 */
class StudioHoliday extends Model
{
    protected $fillable = [
        'date',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
