<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_payment_details', function (Blueprint $table) {
            $table->text('shop_voucher', 15, 2)->nullable();
            $table->decimal('ams_commission_fee', 15, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_payment_details', function (Blueprint $table) {
            //
        });
    }
};
