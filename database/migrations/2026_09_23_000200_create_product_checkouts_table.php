<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A priced, not-yet-paid product checkout. It is NOT an order: the
        // product order is only created from it after the Razorpay payment
        // signature has been verified server-side.
        Schema::create('product_checkouts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('phone', 30);
            // Server-priced line snapshots (App\Support\OrderPricing output).
            $table->json('lines');
            $table->decimal('amount', 12, 2);
            $table->string('razorpay_order_id')->unique();
            $table->string('razorpay_payment_id')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('amount_paid', 12, 2)->nullable()->after('total');
            $table->timestamp('paid_at')->nullable()->after('razorpay_payment_id');

            // One Razorpay order can only ever produce one product order.
            $table->dropIndex(['razorpay_order_id']);
            $table->unique('razorpay_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['razorpay_order_id']);
            $table->index('razorpay_order_id');
            $table->dropColumn(['amount_paid', 'paid_at']);
        });

        Schema::dropIfExists('product_checkouts');
    }
};
