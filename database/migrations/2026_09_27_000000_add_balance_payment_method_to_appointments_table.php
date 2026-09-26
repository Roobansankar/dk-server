<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // How the remaining balance of an online booking was paid at the
            // salon: upi | cash | card. The advance stays on the Razorpay
            // columns; null until the balance is actually collected.
            $table->string('balance_payment_method', 20)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('balance_payment_method');
        });
    }
};
