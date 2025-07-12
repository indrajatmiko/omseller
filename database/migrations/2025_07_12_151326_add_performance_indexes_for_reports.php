<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 1. Indeks paling penting untuk order_status_histories
        Schema::table('order_status_histories', function (Blueprint $table) {
            // Nama indeks bisa kustom, e.g., 'histories_order_status_pickup_index'
            $table->index(['status', 'pickup_time'], 'histories_order_status_pickup_index');
        });

        // 4. Indeks untuk mempercepat JOIN di order_items
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('variant_sku');
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            // Gunakan nama indeks yang sama untuk menghapusnya
            $table->dropIndex('histories_order_status_pickup_index');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['variant_sku']);
        });

    }
};