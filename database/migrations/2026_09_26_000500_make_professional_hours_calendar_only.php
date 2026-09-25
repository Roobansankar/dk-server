<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A professional is bookable ONLY on the calendar dates an admin has given
     * hours for — nothing is selected by default. That retires the weekly
     * pattern (which every professional was auto-filled with, so every date
     * looked pre-selected) and the "day off" marker (a date with no hours is
     * simply not available).
     */
    public function up(): void
    {
        Schema::dropIfExists('stylist_work_hours');

        DB::table('stylist_date_hours')
            ->where(fn ($q) => $q->whereNull('start_time')->orWhereNull('end_time'))
            ->delete();

        Schema::table('stylist_date_hours', function (Blueprint $table) {
            $table->time('start_time')->nullable(false)->change();
            $table->time('end_time')->nullable(false)->change();
        });
    }

    /** Restores the empty structures (the weekly rows themselves are not recoverable). */
    public function down(): void
    {
        Schema::table('stylist_date_hours', function (Blueprint $table) {
            $table->time('start_time')->nullable()->change();
            $table->time('end_time')->nullable()->change();
        });

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
};
