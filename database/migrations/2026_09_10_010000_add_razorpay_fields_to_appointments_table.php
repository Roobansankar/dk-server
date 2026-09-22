<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Links an online booking to its Razorpay Order/Payment. The order
            // id is set once, at order-creation time, and re-used on a retry
            // (see App\Support\Razorpay + Public\PaymentController) so a
            // dropped/cancelled checkout does not spawn a second order for the
            // same appointment. The payment id is only set after a verified
            // signature confirms the appointment.
            $table->string('razorpay_order_id', 64)->nullable()->after('payment_status');
            $table->string('razorpay_payment_id', 64)->nullable()->unique()->after('razorpay_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['razorpay_order_id', 'razorpay_payment_id']);
        });
    }
};
