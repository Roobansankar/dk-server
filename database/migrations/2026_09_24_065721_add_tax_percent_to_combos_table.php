<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combos', function (Blueprint $table) {
            $table->decimal('tax_percent', 5, 2)
                ->default(0)
                ->after('bundle_price');
        });
    }

    public function down(): void
    {
        Schema::table('combos', function (Blueprint $table) {
            $table->dropColumn('tax_percent');
        });
    }
};
