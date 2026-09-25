<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One bookable range on one weekday for a professional (0 = Sunday … 6 =
 * Saturday). Several rows on the same day make a split shift; no rows on a
 * day means a day off.
 */
class StylistWorkHour extends Model
{
    protected $fillable = [
        'stylist_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
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
     * Group rows into the weekly shape the API and the admin editor share: a
     * list of seven days (index = day_of_week), each a list of
     * {start, end} ranges in time order.
     *
     * @param  Collection<int, StylistWorkHour>  $rows
     * @return array<int, array<int, array{start: string, end: string}>>
     */
    public static function weekly(Collection $rows): array
    {
        $week = array_fill(0, 7, []);

        foreach ($rows->sortBy(fn (self $r) => [$r->day_of_week, $r->start()]) as $row) {
            $week[$row->day_of_week][] = ['start' => $row->start(), 'end' => $row->end()];
        }

        return $week;
    }
}
