<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combos', function (Blueprint $table) {
            $table->decimal('bundle_price', 10, 2)
                ->nullable()
                ->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('combos', function (Blueprint $table) {
            $table->dropColumn('bundle_price');
        });
    }
};
