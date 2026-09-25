<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Until now every professional could do every service during the studio's
     * hours (with a fixed midday break). Carry that forward as explicit data so
     * existing professionals stay bookable exactly as before, and the admin can
     * narrow it down per person from the new "Services & hours" page.
     *
     * Only ever fills in professionals that have nothing yet, so it is safe to
     * re-run and never overwrites anything an admin has set.
     */
    public function up(): void
    {
        $opens = DB::table('site_settings')->where('key', 'shop_opens_at')->value('value') ?: '10:00';
        $closes = DB::table('site_settings')->where('key', 'shop_closes_at')->value('value') ?: '19:30';

        $ranges = self::rangesAroundBreak($opens, $closes, '13:00', '14:00');

        $serviceIds = DB::table('services')->whereNull('deleted_at')->pluck('id');
        $now = now();

        foreach (DB::table('stylists')->whereNull('deleted_at')->pluck('id') as $stylistId) {
            if (! DB::table('stylist_service')->where('stylist_id', $stylistId)->exists()) {
                DB::table('stylist_service')->insert(
                    $serviceIds->map(fn ($serviceId) => [
                        'stylist_id' => $stylistId,
                        'service_id' => $serviceId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            }

            if (! DB::table('stylist_work_hours')->where('stylist_id', $stylistId)->exists()) {
                $rows = [];
                foreach (range(0, 6) as $day) {
                    foreach ($ranges as [$start, $end]) {
                        $rows[] = [
                            'stylist_id' => $stylistId,
                            'day_of_week' => $day,
                            'start_time' => $start,
                            'end_time' => $end,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                DB::table('stylist_work_hours')->insert($rows);
            }
        }
    }

    /** Data-only backfill: nothing to undo (the tables are dropped by their own migrations). */
    public function down(): void {}

    /** [open, close] minus the break → one or two ranges, dropping any that collapse to nothing. */
    private static function rangesAroundBreak(string $open, string $close, string $breakStart, string $breakEnd): array
    {
        $ranges = [];

        if ($breakStart <= $open || $breakEnd >= $close) {
            // The break falls outside (or swallows) the opening hours — keep one plain range.
            return $open < $close ? [[$open, $close]] : [];
        }

        $ranges[] = [$open, $breakStart];
        $ranges[] = [$breakEnd, $close];

        return $ranges;
    }
};
