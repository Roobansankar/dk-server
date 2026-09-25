<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One bookable range on one calendar date for a professional. Several rows on
 * the same date make a split shift; a date with no rows is a date they are not
 * available — nothing is bookable unless an admin has set it on the calendar.
 */
class StylistDateHour extends Model
{
    protected $fillable = [
        'stylist_id',
        'date',
        'start_time',
        'end_time',
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
     * Group rows by date into the shape the API and the admin calendar share:
     * `{ "2026-10-06": [{start, end}, …] }`. A date that is not listed is a
     * date the professional isn't available. Returned as an object so an empty
     * map serialises as `{}` rather than `[]`.
     *
     * @param  Collection<int, StylistDateHour>  $rows
     */
    public static function byDate(Collection $rows): object
    {
        $map = [];

        foreach ($rows->sortBy(fn (self $r) => [$r->date->toDateString(), $r->start()]) as $row) {
            $map[$row->date->toDateString()][] = ['start' => $row->start(), 'end' => $row->end()];
        }

        return (object) $map;
    }
}
