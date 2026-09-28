<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Studio-wide closed days (e.g. Diwali) — one entry closes the whole
     * studio for every professional at once, unlike stylist_date_closures
     * which is per-professional. See BookingAvailability::windows().
     */
    public function up(): void
    {
        Schema::create('studio_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_holidays');
    }
};
