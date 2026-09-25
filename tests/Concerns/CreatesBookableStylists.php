<?php

namespace Tests\Concerns;

use App\Models\Service;
use App\Models\Stylist;

/**
 * Online booking only accepts a professional who offers the service and is
 * working at the requested time. These helpers set that up the way the studio
 * ran before per-professional setup existed: every day, apart from the fixed
 * 1–2 PM break, with the studio's opening hours (site settings) still
 * clipping the range at either end.
 */
trait CreatesBookableStylists
{
    /** Ranges wide enough that the studio's saved opening hours are what actually bound the day. */
    protected function everyDayExceptTheBreak(): array
    {
        return [['00:00', '13:00'], ['14:00', '23:59']];
    }

    /** Give a professional working hours (all seven days) — replaces any existing ones. */
    protected function giveWorkHours(Stylist $stylist, ?array $ranges = null): Stylist
    {
        $stylist->workHours()->delete();

        foreach (range(0, 6) as $day) {
            foreach ($ranges ?? $this->everyDayExceptTheBreak() as [$start, $end]) {
                $stylist->workHours()->create([
                    'day_of_week' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                ]);
            }
        }

        return $stylist->refresh();
    }

    /** Make an existing professional able to take bookings for these services. */
    protected function offerServices(Stylist $stylist, Service ...$services): Stylist
    {
        $stylist->services()->syncWithoutDetaching(collect($services)->pluck('id')->all());

        if (! $stylist->workHours()->exists()) {
            $this->giveWorkHours($stylist);
        }

        return $stylist;
    }

    /** A fresh professional who offers the given services. */
    protected function bookableStylistFor(Service ...$services): Stylist
    {
        return $this->offerServices(Stylist::factory()->create(), ...$services);
    }
}
