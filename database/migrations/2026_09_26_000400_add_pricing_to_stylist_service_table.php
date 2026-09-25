<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A professional's own terms for a service they offer. NULL means "use the
     * service's standard price / advance", so nothing changes until an admin
     * sets a value on the professional's Services & hours page.
     */
    public function up(): void
    {
        Schema::table('stylist_service', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('service_id');
            $table->unsignedTinyInteger('advance_percentage')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('stylist_service', function (Blueprint $table) {
            $table->dropColumn(['price', 'advance_percentage']);
        });
    }
};
