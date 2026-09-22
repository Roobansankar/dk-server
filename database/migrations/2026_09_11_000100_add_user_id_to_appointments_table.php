<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links an appointment to the authenticated customer/staff account that
     * created it, when there was one. Nullable — every existing appointment
     * and every future guest booking (no bearer token) keeps user_id = null
     * and continues to work exactly as before. Set server-side only (see
     * Api\Public\AppointmentController::store); never accepted from the
     * request body.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
