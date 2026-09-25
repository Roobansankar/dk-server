<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // How an offline (staff-created) appointment was paid: upi | cash | card.
            // Nullable so existing and online (Razorpay) appointments stay valid.
            $table->string('payment_method', 20)->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
