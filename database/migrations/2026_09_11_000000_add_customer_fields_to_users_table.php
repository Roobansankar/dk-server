<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds customer-account support to the existing (staff-only) users table.
     * `type` distinguishes staff (admin panel, roles/permissions) from
     * customers (public account, appointment history) without a second
     * table/guard. Every existing row defaults to 'staff', so nothing about
     * the current admin panel changes.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('type', 20)->default('staff')->after('email')->index();
            $table->string('phone', 30)->nullable()->after('type');
            $table->string('google_id')->nullable()->unique()->after('phone');
            $table->string('google_avatar_url')->nullable()->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['type', 'phone', 'google_id', 'google_avatar_url']);
        });
    }
};
