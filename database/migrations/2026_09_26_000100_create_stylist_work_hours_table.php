<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A professional's weekly working hours. One row is one bookable range on
     * one weekday (0 = Sunday … 6 = Saturday, matching Carbon::dayOfWeek), so a
     * day can have several ranges (a lunch gap is simply the space between two)
     * and a day with no rows is a day off.
     */
    public function up(): void
    {
        Schema::create('stylist_work_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stylist_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['stylist_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stylist_work_hours');
    }
};
