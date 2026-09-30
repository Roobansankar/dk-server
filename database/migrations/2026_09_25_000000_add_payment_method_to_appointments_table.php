<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formerly `migration_new_add_payment_method_to_appointments_table.php`. That
 * undated name sorted after every dated migration, so a fresh `migrate` ran it
 * last — after 2026_09_27_000000_add_balance_payment_method_to_appointments_table,
 * which places its column `->after('payment_method')` and failed. The dated
 * name makes it run before that migration. Databases that already ran it under
 * the old name already have the column, so the guards make it a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('appointments', 'payment_method')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            // How an offline (staff-created) appointment was paid: upi | cash | card.
            // Nullable so existing and online (Razorpay) appointments stay valid.
            $table->string('payment_method', 20)->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('appointments', 'payment_method')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
