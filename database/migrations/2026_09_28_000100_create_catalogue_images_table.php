<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Up to four photos for a product or a combo, in the order they are shown on
     * its detail page (the first is the cover used on cards and in search).
     * One table serves both — `imageable_type` is the short name registered in
     * AppServiceProvider ("product" / "combo").
     *
     * `products.image_path` / `combos.image_path` stay as the cover so nothing
     * that already reads them changes; the photo each item has today is carried
     * over here as its first photo.
     */
    public function up(): void
    {
        Schema::create('catalogue_images', function (Blueprint $table) {
            $table->id();
            $table->string('imageable_type', 40);
            $table->unsignedBigInteger('imageable_id');
            $table->string('path');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['imageable_type', 'imageable_id', 'sort_order'], 'catalogue_images_owner_order_index');
        });

        $now = now();

        foreach (['product' => 'products', 'combo' => 'combos'] as $type => $table) {
            DB::table($table)->whereNotNull('image_path')->orderBy('id')->each(function ($row) use ($type, $now) {
                DB::table('catalogue_images')->insert([
                    'imageable_type' => $type,
                    'imageable_id' => $row->id,
                    'path' => $row->image_path,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_images');
    }
};
