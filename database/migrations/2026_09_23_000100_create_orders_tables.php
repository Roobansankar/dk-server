<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('phone', 30);

            $table->decimal('subtotal', 12, 2);
            $table->decimal('total', 12, 2);

            $table->string('status', 20)->default('pending');
            $table->string('payment_status', 20)->default('unpaid');

            $table->string('razorpay_order_id')->nullable()->index();
            $table->string('razorpay_payment_id')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('payment_status');
        });

        // Every price/name here is a snapshot taken when the order was placed;
        // product_id / combo_id are kept only as references and may be nulled
        // if the source row is ever hard-deleted.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20); // product | combo
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('combo_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('unit_price', 10, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });

        // The exact products a customer picked from a combo, each with the
        // combo-specific price it carried at purchase time.
        Schema::create('order_item_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_products');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
