<?php

namespace Tests\Concerns;

use App\Models\Service;
use App\Models\Stylist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Online booking only accepts a professional who offers the service and has
 * hours set on the requested calendar date. These helpers set that up for the
 * tests whose subject is something else (buffers, payments, history…): the
 * professional is given hours on every date of a window, the way an admin
 * would fill in the calendar, apart from the fixed 1–2 PM break — with the
 * studio's opening hours (site settings) still clipping the range at either end.
 */
trait CreatesBookableStylists
{
    /** Ranges wide enough that the studio's saved opening hours are what actually bound the day. */
    protected function everyDayExceptTheBreak(): array
    {
        return [['00:00', '13:00'], ['14:00', '23:59']];
    }

    /**
     * The calendar dates a helper-made professional works: a couple of days
     * back through the next few months. Tests that book on fixed dates
     * override this.
     *
     * @return array<int, string>
     */
    protected function workDates(): array
    {
        $today = Carbon::now('Asia/Kolkata')->startOfDay();

        return array_map(fn (int $offset) => $today->copy()->addDays($offset)->toDateString(), range(-2, 90));
    }

    /** Give a professional hours on every date in workDates() — replaces any existing ones. */
    protected function giveWorkHours(Stylist $stylist, ?array $ranges = null): Stylist
    {
        $stylist->dateHours()->delete();

        $now = now();
        $rows = [];

        foreach ($this->workDates() as $date) {
            foreach ($ranges ?? $this->everyDayExceptTheBreak() as [$start, $end]) {
                $rows[] = [
                    'stylist_id' => $stylist->id,
                    'date' => $date,
                    'start_time' => $start,
                    'end_time' => $end,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('stylist_date_hours')->insert($rows);

        return $stylist->refresh();
    }

    /** Make an existing professional able to take bookings for these services. */
    protected function offerServices(Stylist $stylist, Service ...$services): Stylist
    {
        $stylist->services()->syncWithoutDetaching(collect($services)->pluck('id')->all());

        if (! $stylist->dateHours()->exists()) {
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
