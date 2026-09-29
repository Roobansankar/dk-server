<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A combo offer's own time needed (minutes) — same convention as
     * services.duration_minutes. Nullable so existing combos are untouched;
     * a combo needs one before it can be booked as an offline appointment.
     */
    public function up(): void
    {
        Schema::table('combos', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('tax_percent');
        });
    }

    public function down(): void
    {
        Schema::table('combos', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }
};
