<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical appointment records (imported from the salon's own spreadsheets)
 * sometimes have no recorded appointment time. Allow NULL so those rows can be
 * kept accurately instead of being dropped or given a fabricated time. New
 * online/offline bookings still require a time — that is enforced by the
 * StoreAppointmentRequest / StoreOfflineAppointmentRequest form requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->time('appointment_time')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->time('appointment_time')->nullable(false)->change();
        });
    }
};
