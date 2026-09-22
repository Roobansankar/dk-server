<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manually admin-entered Google reviews shown on the public homepage.
     * There is no scraping/API sync — staff type these in from what a
     * customer actually posted on Google. Mirrors gallery_images.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('reviewer_name');
            $table->unsignedTinyInteger('rating');
            $table->text('review_text');
            $table->date('review_date')->nullable();
            $table->string('reviewer_avatar_path')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_published');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
