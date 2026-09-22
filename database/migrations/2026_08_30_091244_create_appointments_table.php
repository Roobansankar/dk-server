<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('customer_name');
            $table->string('phone', 30);
            $table->string('gender', 10); // male | female | unisex

            // Relationship to the catalogue, kept nullable so deleting a service
            // never destroys appointment history.
            $table->foreignId('service_category_id')->nullable()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->nullOnDelete();

            // Denormalised snapshot of what was booked, for historical integrity.
            $table->string('category_name')->nullable();
            $table->string('service_name')->nullable();

            $table->date('appointment_date');
            $table->time('appointment_time');
            $table->text('message')->nullable();
            $table->text('notes')->nullable(); // internal, admin only
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['status', 'appointment_date']);
            $table->index('appointment_date');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
