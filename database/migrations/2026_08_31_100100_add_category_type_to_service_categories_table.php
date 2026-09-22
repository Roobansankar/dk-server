<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            // hair | skin — nullable so every existing category stays valid.
            $table->string('category_type', 10)->nullable()->after('description');
            $table->index('category_type');
        });

        // Best-effort backfill from the category name. Anything the name doesn't
        // clearly identify is left null for an admin to set.
        DB::table('service_categories')
            ->whereNull('category_type')
            ->where('name', 'like', '%hair%')
            ->update(['category_type' => 'hair']);

        DB::table('service_categories')
            ->whereNull('category_type')
            ->where(function ($q) {
                $q->where('name', 'like', '%skin%')
                    ->orWhere('name', 'like', '%facial%');
            })
            ->update(['category_type' => 'skin']);
    }

    public function down(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropIndex(['category_type']);
            $table->dropColumn('category_type');
        });
    }
};
