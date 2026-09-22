<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // online (public booking) | offline (created by salon staff).
            // Existing rows default to online — they were all public bookings.
            $table->string('source', 10)->default('online')->after('status');
            $table->index('source');

            // Which stylist the appointment is with. Nullable FK ("any available"
            // stylist / legacy rows) plus a denormalised snapshot so history
            // survives a stylist being removed — mirrors service_id/service_name.
            $table->foreignId('stylist_id')->nullable()->after('service_id')->nullOnDelete();
            $table->string('stylist_name')->nullable()->after('service_name');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
            $table->dropConstrainedForeignId('stylist_id');
            $table->dropColumn('stylist_name');
        });
    }
};
