<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Snapshot of the service configuration at booking time, so history
            // and payment records stay accurate even if the service changes.
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('service_name');
            $table->decimal('service_price', 10, 2)->nullable()->after('duration_minutes');
            $table->unsignedTinyInteger('advance_percentage')->default(0)->after('service_price');
            $table->decimal('advance_amount', 10, 2)->nullable()->after('advance_percentage');

            // Lightweight payment record — not a payment gateway.
            // unpaid | advance_paid | paid
            $table->string('payment_status', 20)->default('unpaid')->after('advance_amount');

            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
            $table->dropColumn([
                'duration_minutes',
                'service_price',
                'advance_percentage',
                'advance_amount',
                'payment_status',
            ]);
        });
    }
};
