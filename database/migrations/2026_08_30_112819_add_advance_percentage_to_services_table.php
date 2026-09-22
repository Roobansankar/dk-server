<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Advance payment the studio asks for to confirm a booking, as a
            // percentage of the service price. 0 = no advance required.
            $table->unsignedTinyInteger('advance_percentage')->default(0)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('advance_percentage');
        });
    }
};
