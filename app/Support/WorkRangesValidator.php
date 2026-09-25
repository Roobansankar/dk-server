<?php

namespace App\Support;

use Illuminate\Validation\Validator;

/**
 * Checks one day's list of `{start, end}` working ranges the same way wherever
 * an admin enters them — the weekly pattern and the calendar's date-specific
 * hours: end after start, no overlaps, and inside the studio's opening hours.
 * Errors are added under "<prefix>.<index>.start|end" so the admin form can
 * show them next to the offending range.
 */
class WorkRangesValidator
{
    /**
     * @param  array<int, array{start: string, end: string}>  $ranges  Already format-validated ("H:i")
     */
    public static function validate(Validator $validator, array $ranges, string $prefix): void
    {
        $bounds = BookingAvailability::shopBounds();
        $previousEnd = null;

        $ordered = collect($ranges)
            ->map(fn ($range, $index) => ['index' => $index, 'start' => $range['start'], 'end' => $range['end']])
            ->sortBy('start')
            ->values();

        foreach ($ordered as $range) {
            $key = "$prefix.{$range['index']}";
            $start = BookingAvailability::minutes($range['start']);
            $end = BookingAvailability::minutes($range['end']);

            if ($end <= $start) {
                $validator->errors()->add("$key.end", 'The end time must be after the start time.');

                continue;
            }

            if ($previousEnd !== null && $start < $previousEnd) {
                $validator->errors()->add("$key.start", 'This overlaps another range on the same day.');

                continue;
            }

            if ($bounds && ($start < $bounds[0] || $end > $bounds[1])) {
                $validator->errors()->add("$key.start", sprintf(
                    'Hours must be within the studio\'s opening hours (%s – %s). Change the studio hours in Settings first.',
                    date('g:i A', strtotime(BookingAvailability::format($bounds[0]))),
                    date('g:i A', strtotime(BookingAvailability::format($bounds[1]))),
                ));

                continue;
            }

            $previousEnd = $end;
        }
    }
}
