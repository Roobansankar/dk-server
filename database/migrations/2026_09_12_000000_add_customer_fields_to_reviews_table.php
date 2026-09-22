<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a signed-in customer submit their own review through the same
     * `reviews` table the admin-entered Google reviews already live in —
     * no separate architecture. `source` distinguishes the two ('google' for
     * every existing/staff-entered row, 'customer' for one a visitor
     * submitted); `user_id` links a customer submission back to their
     * account, set server-side only (see Api\Public\ReviewController::store)
     * and never accepted from the request body. Both are nullable/defaulted,
     * so every existing row keeps working unchanged and still needs no
     * source/user assigned.
     */
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('google')->after('user_id');

            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
