<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which services each professional offers. A service already belongs to a
     * gendered category, so this one table also decides which genders and
     * categories a professional serves — there is nothing else to store.
     */
    public function up(): void
    {
        Schema::create('stylist_service', function (Blueprint $table) {
            $table->foreignId('stylist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['stylist_id', 'service_id']);
            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stylist_service');
    }
};
