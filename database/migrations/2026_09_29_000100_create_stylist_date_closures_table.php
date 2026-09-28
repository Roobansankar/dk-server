<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A specific calendar date an admin has explicitly marked "not available"
     * for a professional — a day off despite what their weekly schedule
     * (stylist_weekly_hours) would otherwise say, e.g. a holiday or sick day.
     *
     * Only meaningful when the date has no stylist_date_hours rows of its own:
     * specific hours for a date always win over a closure, exactly like they
     * win over the weekly schedule. See BookingAvailability::windows().
     */
    public function up(): void
    {
        Schema::create('stylist_date_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stylist_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamps();

            $table->unique(['stylist_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stylist_date_closures');
    }
};
