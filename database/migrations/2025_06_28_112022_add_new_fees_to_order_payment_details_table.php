<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('order_payment_details', function (Blueprint $table) {
        $table->decimal('shipping_fee_subtotal', 15, 2)->nullable()->after('shopee_shipping_subsidy');
        $table->decimal('shipping_fee_estimate', 15, 2)->nullable()->after('shipping_fee_subtotal');
        $table->decimal('other_fees', 15, 2)->nullable()->after('ams_commission_fee');
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
