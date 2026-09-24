<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combos', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // One row per product included in a combo. `price` is the
        // combo-specific price configured by the admin — deliberately stored
        // here rather than read from products.selling_price, so editing the
        // product's storefront price never silently changes the combo.
        Schema::create('combo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['combo_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combo_items');
        Schema::dropIfExists('combos');
    }
};
