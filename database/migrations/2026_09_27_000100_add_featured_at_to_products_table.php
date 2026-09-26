<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // When the product was last switched to featured — orders the
            // featured set so the oldest drops off when a 4th is featured.
            $table->timestamp('featured_at')->nullable()->after('is_featured');
        });

        DB::table('products')->where('is_featured', true)->update(['featured_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('featured_at');
        });
    }
};
