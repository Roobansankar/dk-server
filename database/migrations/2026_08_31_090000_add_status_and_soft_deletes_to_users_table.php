<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensures the `users` table has `status` and `deleted_at`.
 *
 * The base users migration was later amended to include these, so a database
 * created from scratch already has them (this migration then does nothing).
 * Databases whose users table predates that amendment get the columns added
 * here. Idempotent and safe to run on any database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $needsStatus = ! Schema::hasColumn('users', 'status');
        $needsSoftDeletes = ! Schema::hasColumn('users', 'deleted_at');

        if (! $needsStatus && ! $needsSoftDeletes) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($needsStatus, $needsSoftDeletes) {
            if ($needsStatus) {
                $table->string('status', 20)->default('active')->after('password')->index();
            }
            if ($needsSoftDeletes) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        $hasSoftDeletes = Schema::hasColumn('users', 'deleted_at');
        $hasStatus = Schema::hasColumn('users', 'status');

        if (! $hasSoftDeletes && ! $hasStatus) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($hasStatus, $hasSoftDeletes) {
            if ($hasSoftDeletes) {
                $table->dropSoftDeletes();
            }
            if ($hasStatus) {
                $table->dropColumn('status');
            }
        });
    }
};
