<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One calendar date a professional is explicitly not available on, despite
 * what their standing weekly schedule (StylistWeeklyHour) would otherwise
 * say — a holiday, a sick day, time off. See BookingAvailability::windows().
 */
class StylistDateClosure extends Model
{
    protected $fillable = [
        'stylist_id',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function stylist(): BelongsTo
    {
        return $this->belongsTo(Stylist::class);
    }
}
