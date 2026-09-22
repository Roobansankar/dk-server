<?php

namespace App\Support;

use App\Models\Appointment;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Server-side slot locking for confirmed appointments.
 *
 * A confirmed appointment locks its FULL service duration for its stylist:
 * a 2:00 PM booking of a 90-minute service blocks 2:00–3:30 PM. Any other
 * appointment for the SAME stylist that overlaps that range — same start,
 * starts inside, ends inside, contains it, or partially overlaps either way —
 * is a conflict. The interval is half-open: an appointment ending at 3:30
 * does not conflict with one starting at 3:30.
 *
 * Appointments with no stylist ("any available" — the studio assigns one) are
 * not range-checked: there is no per-chair capacity model, and blocking every
 * stylist for an unassigned booking would over-restrict. Only same-stylist
 * overlaps are rejected.
 */
class AppointmentSlots
{
    /**
     * Minimum gap the ONLINE booking flow requires between two sessions for
     * the same stylist (StoreAppointmentRequest, PaymentController::verify —
     * see [[booking-time-slots]]). Not applied to the admin/offline paths
     * (AppointmentController, StoreOfflineAppointmentRequest), which call
     * findConflict() with the default $bufferMinutes = 0 and keep their
     * existing back-to-back-allowed behaviour untouched.
     */
    public const ONLINE_BUFFER_MINUTES = 10;

    /** The studio's fixed midday break — no bookable start may fall inside it. */
    public const STUDIO_BREAKS = [['13:00', '14:00']];

    /**
     * Granularity the earliest bookable moment of "today" rounds UP to.
     * Mirrors the booking form's own picker (ROUND_TO_MIN in
     * frontend/src/data/bookingTimes.js — see [[booking-time-slots]]) — this
     * is the single authoritative copy of that rule; the frontend value must
     * stay in step with this one by convention (there is no way to share a
     * literal across PHP and JS here), not by duplicating the algorithm.
     */
    public const SLOT_ROUNDING_MINUTES = 10;

    /**
     * The earliest instant, on $date, that an ONLINE booking may start —
     * or null when $date isn't "today" (any other day has no clock floor at
     * all; the picker's cadence starts at opening).
     *
     * "Now" is rounded UP to the next SLOT_ROUNDING_MINUTES boundary and is
     * always STRICTLY later than "now" itself — e.g. at 10:40 the floor is
     * 10:50, never 10:40, so a slot exactly "now" is never offered (by the
     * time it could be tapped/submitted it has already happened). At 10:10
     * the floor is 10:20, not 10:10 — the boundary itself never re-qualifies.
     */
    public static function earliestBookableTime(Carbon $date, Carbon $now): ?Carbon
    {
        if (! $date->isSameDay($now)) {
            return null;
        }

        $minutesSinceMidnight = $now->hour * 60 + $now->minute;
        $rounded = intdiv($minutesSinceMidnight, self::SLOT_ROUNDING_MINUTES) * self::SLOT_ROUNDING_MINUTES
            + self::SLOT_ROUNDING_MINUTES;

        return $date->copy()->startOfDay()->addMinutes($rounded);
    }

    /** Half-open interval overlap: [aStart, aEnd) ∩ [bStart, bEnd) ≠ ∅. */
    public static function overlaps(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): bool
    {
        return $aStart->lt($bEnd) && $bStart->lt($aEnd);
    }

    /**
     * The first CONFIRMED appointment whose locked range — optionally padded
     * by $bufferMinutes on both sides — overlaps [$startTime, $startTime +
     * $durationMinutes) for $stylistId on $date.
     *
     * $bufferMinutes defaults to 0, preserving the existing admin/offline
     * back-to-back-allowed behaviour. Pass ONLINE_BUFFER_MINUTES from the
     * online booking flow to require a gap between sessions.
     *
     * Returns null when there is no stylist, no usable time/duration, or no
     * conflict. Callers that then mutate state should hold a row lock first
     * (see lockDay()) inside the same transaction.
     */
    public static function findConflict(
        ?int $stylistId,
        DateTimeInterface|string|null $date,
        ?string $startTime,
        ?int $durationMinutes,
        ?int $ignoreAppointmentId = null,
        int $bufferMinutes = 0,
    ): ?Appointment {
        if (! $stylistId || ! $date || ! $startTime || ! $durationMinutes || $durationMinutes < 1) {
            return null;
        }

        $dateStr = $date instanceof DateTimeInterface
            ? $date->format('Y-m-d')
            : Carbon::parse($date)->toDateString();

        $start = self::time($startTime);
        $end = (clone $start)->addMinutes($durationMinutes);

        return Appointment::query()
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->where('stylist_id', $stylistId)
            ->whereDate('appointment_date', $dateStr)
            ->when($ignoreAppointmentId, fn ($q) => $q->whereKeyNot($ignoreAppointmentId))
            ->get()
            ->first(function (Appointment $other) use ($start, $end, $bufferMinutes) {
                if (! $other->appointment_time || ! $other->duration_minutes) {
                    return false;
                }
                $oStart = self::time($other->appointment_time->format('H:i'))->subMinutes($bufferMinutes);
                $oEnd = self::time($other->appointment_time->format('H:i'))
                    ->addMinutes((int) $other->duration_minutes)
                    ->addMinutes($bufferMinutes);

                return self::overlaps($start, $end, $oStart, $oEnd);
            });
    }

    /**
     * Whether [$startTime, $startTime + $durationMinutes) starts inside, or
     * crosses, any of the studio's fixed breaks (STUDIO_BREAKS). Null/unusable
     * input is never in a break.
     */
    public static function crossesBreak(?string $startTime, ?int $durationMinutes): bool
    {
        if (! $startTime || ! $durationMinutes || $durationMinutes < 1) {
            return false;
        }

        $start = self::time($startTime);
        $end = (clone $start)->addMinutes($durationMinutes);

        foreach (self::STUDIO_BREAKS as [$breakStart, $breakEnd]) {
            if (self::overlaps($start, $end, self::time($breakStart), self::time($breakEnd))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Acquire a write lock on every appointment row for this stylist on this
     * date, so two concurrent confirmations for the same stylist serialise and
     * cannot both pass the conflict check. No-op without a stylist. MUST be
     * called inside a database transaction.
     */
    public static function lockDay(?int $stylistId, DateTimeInterface|string|null $date): void
    {
        if (! $stylistId || ! $date) {
            return;
        }

        $dateStr = $date instanceof DateTimeInterface
            ? $date->format('Y-m-d')
            : Carbon::parse($date)->toDateString();

        Appointment::query()
            ->where('stylist_id', $stylistId)
            ->whereDate('appointment_date', $dateStr)
            ->lockForUpdate()
            ->get(['id']);
    }

    /**
     * Lock the stylist's whole day (see lockDay()), THEN this one appointment's
     * own row — in that fixed order, for every caller that needs to hold both
     * locks before inspecting/mutating the target row. MUST be called inside a
     * database transaction.
     *
     * The order matters: locking a single row first and the broader day second
     * lets two concurrent requests for two DIFFERENT appointments on the same
     * stylist/day each hold a different row, then each block waiting on
     * lockDay() for the row the other already holds — a lock-order inversion
     * that deadlocks (InnoDB error 1213, surfaced as an unhandled 500) instead
     * of one request cleanly losing with a 422. Locking the day first means
     * every concurrent request for that stylist/day contends for the same
     * lock in the same order and simply queues.
     *
     * Used by PaymentController::verify() and the admin appointment
     * confirm/update actions — the two places a pending appointment's slot
     * gets authoritatively re-checked and locked in.
     */
    public static function lockDayThenAppointment(
        int $appointmentId,
        ?int $stylistId,
        DateTimeInterface|string|null $date,
    ): Appointment {
        self::lockDay($stylistId, $date);

        return Appointment::whereKey($appointmentId)->lockForUpdate()->firstOrFail();
    }

    /** "14:00" + 90 => "15:30"; null when either input is missing. */
    public static function endTime(?string $startTime, ?int $durationMinutes): ?string
    {
        if (! $startTime || ! $durationMinutes || $durationMinutes < 1) {
            return null;
        }

        return self::time($startTime)->addMinutes($durationMinutes)->format('H:i');
    }

    /** A human sentence describing a conflicting appointment, for error output. */
    public static function describeConflict(Appointment $conflict): string
    {
        $start = $conflict->appointment_time?->format('H:i');
        $end = self::endTime($start, $conflict->duration_minutes);

        return sprintf(
            'This overlaps a confirmed appointment (%s) with %s on %s from %s to %s.',
            $conflict->reference,
            $conflict->stylist_name ?: 'this stylist',
            $conflict->appointment_date?->toDateString() ?? '',
            $start ?? '?',
            $end ?? '?',
        );
    }

    /** Parse a wall-clock "H:i" onto a fixed reference day for time-of-day math. */
    private static function time(string $hhmm): Carbon
    {
        return Carbon::parse('2000-01-01 '.$hhmm);
    }
}
