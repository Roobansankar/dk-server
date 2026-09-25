<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Working hours for ONE specific date, overriding the weekly pattern (see the
 * migration for the row conventions: times = custom range, NULL times = day off).
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

    /** A "day off" row carries no times. */
    public function isOff(): bool
    {
        return $this->start_time === null || $this->end_time === null;
    }

    public function start(): ?string
    {
        return $this->start_time === null ? null : substr((string) $this->start_time, 0, 5);
    }

    public function end(): ?string
    {
        return $this->end_time === null ? null : substr((string) $this->end_time, 0, 5);
    }

    /**
     * Group rows by date into the shape the API and the admin calendar share:
     * `{ "2026-10-05": [], "2026-10-06": [{start, end}, …] }` — an empty list
     * means the professional is off that day, a missing date means "weekly
     * pattern applies". Returned as an object so an empty map serialises as
     * `{}` rather than `[]`.
     *
     * @param  Collection<int, StylistDateHour>  $rows
     */
    public static function byDate(Collection $rows): object
    {
        $map = [];

        foreach ($rows->sortBy(fn (self $r) => [$r->date->toDateString(), $r->start() ?? '']) as $row) {
            $key = $row->date->toDateString();
            $map[$key] ??= [];

            if (! $row->isOff()) {
                $map[$key][] = ['start' => $row->start(), 'end' => $row->end()];
            }
        }

        return (object) $map;
    }
}
