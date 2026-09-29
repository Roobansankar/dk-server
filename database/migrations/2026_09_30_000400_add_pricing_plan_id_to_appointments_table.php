<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Combo Offer appointment is booked against a Pricing Plan (the public
     * "Combo Offers" are pricing plans). The plan's name / price / duration are
     * snapshotted onto the existing columns (service_name, service_price,
     * duration_minutes) like a service booking; this link is for reference and
     * is nulled if the plan row is ever removed, so history is never lost.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('pricing_plan_id')->nullable()->after('combo_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pricing_plan_id');
        });
    }
};
