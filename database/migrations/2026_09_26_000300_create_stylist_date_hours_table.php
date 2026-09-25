<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Date-specific working hours — what an admin sets by clicking days on the
     * calendar. For a given date these REPLACE the weekly pattern:
     *   - rows with times  → custom hours for that date (several rows = several ranges);
     *   - one row with NULL times → the professional is off that date;
     *   - no rows → the weekly pattern applies as usual.
     */
    public function up(): void
    {
        Schema::create('stylist_date_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stylist_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();

            $table->index(['stylist_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stylist_date_hours');
    }
};
