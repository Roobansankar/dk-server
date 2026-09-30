<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Offline Billing records a walk-in product sale as a normal order.
 * Additive only: existing orders keep their data, get source = online, and
 * have no address / payment method (Razorpay is implied by their payment ids).
 *
 * order_items also gain a tax snapshot. Prices are tax-INCLUSIVE, so these
 * columns only record the tax already inside line_total — they never change
 * what is charged. Existing lines default to 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'customer_address')) {
                $table->text('customer_address')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('orders', 'source')) {
                $table->string('source', 10)->default('online')->after('payment_status');
                $table->index('source');
            }
            if (! Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method', 20)->nullable()->after('payment_status');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'tax_percent')) {
                $table->decimal('tax_percent', 5, 2)->default(0)->after('unit_price');
            }
            if (! Schema::hasColumn('order_items', 'tax_amount')) {
                $table->decimal('tax_amount', 12, 2)->default(0)->after('line_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['tax_percent', 'tax_amount']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['customer_address', 'source', 'payment_method']);
        });
    }
};
