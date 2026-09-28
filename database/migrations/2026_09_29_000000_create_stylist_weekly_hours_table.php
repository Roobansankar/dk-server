<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A professional's standing weekly schedule — "every Monday 10–6", with no
     * end date. One row is one bookable range on one weekday (0 = Sunday … 6 =
     * Saturday, matching Carbon::dayOfWeek), so a day can have several ranges
     * and a weekday with no rows simply isn't part of the pattern.
     *
     * This is the DEFAULT only. A specific calendar date in stylist_date_hours,
     * or an explicit closure in stylist_date_closures, always wins over it —
     * see BookingAvailability::windows().
     */
    public function up(): void
    {
        Schema::create('stylist_weekly_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stylist_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['stylist_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stylist_weekly_hours');
    }
};
