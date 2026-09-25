<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\Stylist;
use App\Models\StylistDateHour;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The single source of truth for "when can this service be booked, and with
 * whom" on the ONLINE booking flow. The public slot list and the server-side
 * validation both come from here, so what the booking page offers is exactly
 * what the API will accept.
 *
 * A time is bookable with a professional when
 *   1. they offer the service (stylist_service),
 *   2. the whole service fits inside one of their working ranges for that
 *      weekday (stylist_work_hours), further limited to the studio's opening
 *      hours when those are set,
 *   3. it is not in the past (rounded like AppointmentSlots::earliestBookableTime), and
 *   4. it doesn't overlap one of their CONFIRMED appointments, padded by
 *      AppointmentSlots::ONLINE_BUFFER_MINUTES.
 *
 * "Any professional" (no stylist chosen) means: any eligible professional who
 * passes the four rules — the first one in roster order is assigned.
 */
class BookingAvailability
{
    /** The studio's clock; every date/time on this flow is in this zone. */
    public const TZ = 'Asia/Kolkata';

    /** Weekly ranges for a professional that has none yet: studio hours around the midday break. */
    public static function defaultRanges(): array
    {
        $bounds = self::shopBounds() ?? [self::minutes('10:00'), self::minutes('19:30')];
        [$open, $close] = $bounds;
        [$breakStart, $breakEnd] = array_map(self::minutes(...), AppointmentSlots::STUDIO_BREAKS[0]);

        $ranges = ($breakStart <= $open || $breakEnd >= $close)
            ? [[$open, $close]]
            : [[$open, $breakStart], [$breakEnd, $close]];

        return array_map(fn ($r) => [self::format($r[0]), self::format($r[1])], $ranges);
    }

    /** The studio's opening hours as [openMinutes, closeMinutes], or null when not (validly) set. */
    public static function shopBounds(): ?array
    {
        $settings = SiteSetting::allValues();
        $opens = $settings->get('shop_opens_at');
        $closes = $settings->get('shop_closes_at');

        if (! $opens || ! $closes) {
            return null;
        }

        $open = self::minutes($opens);
        $close = self::minutes($closes);

        return $open < $close ? [$open, $close] : null;
    }

    /**
     * Active professionals who offer $service and have working hours set,
     * in roster order, with their hours loaded.
     *
     * @return Collection<int, Stylist>
     */
    public static function eligibleStylists(Service $service): Collection
    {
        return Stylist::query()
            ->active()
            ->ordered()
            ->offering($service->id)
            ->where(fn ($q) => $q
                ->whereHas('workHours')
                // …or nothing weekly, but custom hours set for upcoming dates.
                ->orWhereHas('dateHours', fn ($d) => $d
                    ->whereNotNull('start_time')
                    ->whereDate('date', '>=', Carbon::now(self::TZ)->toDateString())))
            ->with('workHours')
            ->get();
    }

    /**
     * A professional's bookable ranges on $dateIso as [startMin, endMin] pairs,
     * sorted, clipped to the studio's opening hours.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function windows(Stylist $stylist, string $dateIso): array
    {
        $bounds = self::shopBounds();
        $ranges = [];

        // A date set on the calendar (custom hours, or a day off) replaces the
        // weekly pattern for that date entirely.
        $override = StylistDateHour::query()
            ->where('stylist_id', $stylist->id)
            ->whereDate('date', $dateIso)
            ->get();

        if ($override->isNotEmpty()) {
            foreach ($override as $row) {
                if (! $row->isOff()) {
                    $ranges[] = [self::minutes($row->start()), self::minutes($row->end())];
                }
            }
        } else {
            $dayOfWeek = Carbon::parse($dateIso, self::TZ)->dayOfWeek;

            foreach ($stylist->workHours as $row) {
                if ($row->day_of_week === $dayOfWeek) {
                    $ranges[] = [self::minutes($row->start()), self::minutes($row->end())];
                }
            }
        }

        $out = [];

        foreach ($ranges as [$start, $end]) {
            if ($bounds) {
                $start = max($start, $bounds[0]);
                $end = min($end, $bounds[1]);
            }

            if ($start < $end) {
                $out[] = [$start, $end];
            }
        }

        usort($out, fn ($a, $b) => $a[0] <=> $b[0]);

        return $out;
    }

    /**
     * The bookable start times for $service on $dateIso — with one professional
     * (their own free time, plus their booked windows for display) or, when
     * $stylist is null, every eligible professional combined.
     *
     * @return array{date: string, working: bool, today_exhausted: bool, slots: array<int, array{start: string, end: string, status: string}>}
     */
    public static function slots(Service $service, ?Stylist $stylist, string $dateIso): array
    {
        $result = ['date' => $dateIso, 'working' => false, 'today_exhausted' => false, 'slots' => []];

        $duration = (int) $service->duration_minutes;
        $now = Carbon::now(self::TZ);

        if ($duration < 1 || $dateIso < $now->toDateString()) {
            return $result;
        }

        $candidates = $stylist
            ? collect([$stylist->loadMissing('workHours')])
            : self::eligibleStylists($service);

        $floorAt = AppointmentSlots::earliestBookableTime(Carbon::parse($dateIso, self::TZ)->startOfDay(), $now);
        $floor = $floorAt ? $floorAt->hour * 60 + $floorAt->minute : null;
        $buffer = AppointmentSlots::ONLINE_BUFFER_MINUTES;

        $starts = [];
        $booked = [];
        $fitsAtAll = false;

        foreach ($candidates as $candidate) {
            $windows = self::windows($candidate, $dateIso);
            $busy = self::busyWindows($candidate, $dateIso);
            $blocks = array_map(fn ($b) => [$b[0] - $buffer, $b[1] + $buffer], $busy);

            if ($windows) {
                $result['working'] = true;
            }

            foreach ($windows as [$windowStart, $windowEnd]) {
                $first = $floor !== null ? max($windowStart, $floor) : $windowStart;

                if ($first + $duration <= $windowEnd) {
                    $fitsAtAll = true;
                }

                if ($first >= $windowEnd) {
                    continue;
                }

                foreach (self::freeIntervals($first, $windowEnd, self::mergeBlocks($blocks, $first, $windowEnd)) as [$freeStart, $freeEnd]) {
                    for ($t = $freeStart; $t + $duration <= $freeEnd; $t += $duration) {
                        $starts[$t] = true;
                    }
                }

                // A chosen professional's existing bookings stay visible (greyed out) so the
                // picker shows why a gap exists.
                if ($stylist) {
                    foreach ($busy as [$busyStart, $busyEnd]) {
                        if ($busyEnd > $first && $busyStart < $windowEnd) {
                            $booked[$busyStart] = $busyEnd;
                        }
                    }
                }
            }
        }

        $result['today_exhausted'] = $floor !== null && $result['working'] && ! $fitsAtAll;

        foreach (array_keys($starts) as $t) {
            $result['slots'][] = [
                'start' => self::format($t),
                'end' => self::format($t + $duration),
                'status' => 'available',
                '_t' => $t,
            ];
        }
        foreach ($booked as $busyStart => $busyEnd) {
            $result['slots'][] = [
                'start' => self::format($busyStart),
                'end' => self::format($busyEnd),
                'status' => 'booked',
                '_t' => $busyStart,
            ];
        }

        usort($result['slots'], fn ($a, $b) => $a['_t'] <=> $b['_t']);
        $result['slots'] = array_map(function ($slot) {
            unset($slot['_t']);

            return $slot;
        }, $result['slots']);

        return $result;
    }

    /**
     * Decide who takes a booking. Returns [professional, null] on success, or
     * [null, message] when the time can't be booked. With $lock the chosen
     * professional's whole day is locked first (call inside a transaction) so
     * two concurrent requests can't both take the same time.
     *
     * @return array{0: ?Stylist, 1: ?string}
     */
    public static function resolve(Service $service, ?Stylist $stylist, string $dateIso, string $time, bool $lock = false): array
    {
        $duration = (int) $service->duration_minutes;

        if ($duration < 1) {
            return [null, 'This service can\'t be booked online yet. Please contact the studio.'];
        }

        $start = self::minutes($time);
        $end = $start + $duration;

        if ($stylist) {
            if (! $stylist->services()->where('services.id', $service->id)->exists()) {
                return [null, sprintf('%s doesn\'t offer this service. Please choose another professional or service.', $stylist->name)];
            }
            $candidates = collect([$stylist->loadMissing('workHours')]);
        } else {
            $candidates = self::eligibleStylists($service);

            if ($candidates->isEmpty()) {
                return [null, 'No professional currently offers this service. Please contact the studio.'];
            }
        }

        $working = false;

        foreach ($candidates as $candidate) {
            $fits = collect(self::windows($candidate, $dateIso))
                ->contains(fn ($w) => $w[0] <= $start && $end <= $w[1]);

            if (! $fits) {
                continue;
            }

            $working = true;

            if ($lock) {
                AppointmentSlots::lockDay($candidate->id, $dateIso);
            }

            $conflict = AppointmentSlots::findConflict(
                $candidate->id,
                $dateIso,
                $time,
                $duration,
                null,
                AppointmentSlots::ONLINE_BUFFER_MINUTES,
            );

            if (! $conflict) {
                return [$candidate, null];
            }
        }

        if ($stylist) {
            return [null, $working
                ? 'That time is no longer available with the selected stylist. Please choose another slot.'
                : sprintf('%s isn\'t working at that time. Please choose another slot.', $stylist->name)];
        }

        return [null, 'No professional is available at that time. Please choose another slot.'];
    }

    /** Confirmed appointments on $dateIso as their real [startMin, endMin] windows. */
    private static function busyWindows(Stylist $stylist, string $dateIso): array
    {
        return Appointment::query()
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->where('stylist_id', $stylist->id)
            ->whereDate('appointment_date', $dateIso)
            ->whereNotNull('appointment_time')
            ->whereNotNull('duration_minutes')
            ->get()
            ->map(function (Appointment $a) {
                $start = self::minutes($a->appointment_time->format('H:i'));

                return [$start, $start + (int) $a->duration_minutes];
            })
            ->all();
    }

    /** Clip each block to [dayStart, dayEnd), drop empties, sort, and merge overlaps. */
    private static function mergeBlocks(array $blocks, int $dayStart, int $dayEnd): array
    {
        $clipped = [];
        foreach ($blocks as [$start, $end]) {
            $start = max($start, $dayStart);
            $end = min($end, $dayEnd);
            if ($start < $end) {
                $clipped[] = [$start, $end];
            }
        }
        usort($clipped, fn ($a, $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($clipped as $block) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex >= 0 && $block[0] <= $merged[$lastIndex][1]) {
                $merged[$lastIndex][1] = max($merged[$lastIndex][1], $block[1]);
            } else {
                $merged[] = $block;
            }
        }

        return $merged;
    }

    /** The gaps left in [dayStart, dayEnd) once the merged blocks are removed. */
    private static function freeIntervals(int $dayStart, int $dayEnd, array $merged): array
    {
        $free = [];
        $cursor = $dayStart;
        foreach ($merged as [$start, $end]) {
            if ($cursor < $start) {
                $free[] = [$cursor, $start];
            }
            $cursor = max($cursor, $end);
        }
        if ($cursor < $dayEnd) {
            $free[] = [$cursor, $dayEnd];
        }

        return $free;
    }

    /** "H:i" or "H:i:s" → minutes since midnight. */
    public static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }

    /** Minutes since midnight → "H:i" (zero-padded). */
    public static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
