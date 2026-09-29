<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An appointment booked for a Combo Offer instead of a service. The combo's
     * name / price / duration are snapshotted onto the existing columns
     * (service_name, service_price, duration_minutes), exactly like a service
     * booking; this link is kept for reference only and is nulled if the combo
     * row is ever removed, so history is never lost.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('combo_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('combo_id');
        });
    }
};
