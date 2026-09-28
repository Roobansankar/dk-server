<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One bookable range on one weekday (0 = Sunday … 6 = Saturday) in a
 * professional's standing weekly schedule — the default a calendar date
 * falls back to when nothing more specific has been set for it. See
 * BookingAvailability::windows() for how this combines with
 * StylistDateHour (a specific date's own hours) and StylistDateClosure (an
 * explicit day off).
 */
class StylistWeeklyHour extends Model
{
    protected $fillable = [
        'stylist_id',
        'weekday',
        'start_time',
        'end_time',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }

    public function stylist(): BelongsTo
    {
        return $this->belongsTo(Stylist::class);
    }

    /** "10:00:00" → "10:00". */
    public function start(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    public function end(): string
    {
        return substr((string) $this->end_time, 0, 5);
    }

    /**
     * Group rows by weekday into the shape the admin setup page uses:
     * `{ "1": [{start, end}, …] }` (string keys "0"…"6", Sunday first,
     * matching JS's `Date#getDay()`). A weekday not listed has no standing
     * hours. Returned as an object so an empty map serialises as `{}`.
     *
     * @param  Collection<int, StylistWeeklyHour>  $rows
     */
    public static function byWeekday(Collection $rows): object
    {
        $map = [];

        foreach ($rows->sortBy(fn (self $r) => [$r->weekday, $r->start()]) as $row) {
            $map[(string) $row->weekday][] = ['start' => $row->start(), 'end' => $row->end()];
        }

        return (object) $map;
    }
}
