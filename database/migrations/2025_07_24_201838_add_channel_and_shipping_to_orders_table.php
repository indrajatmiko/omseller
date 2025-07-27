<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Kolom untuk membedakan channel penjualan
            $table->enum('channel', ['shopee', 'reseller', 'direct'])->default('shopee')->after('user_id');
            
            // Kolom untuk menampung ID reseller
            $table->foreignId('reseller_id')->nullable()->after('channel')->constrained()->onDelete('set null');

            // Kolom generik untuk nama pelanggan (penjualan langsung)
            $table->string('customer_name')->nullable()->after('buyer_username');

            // Kolom generik untuk tanggal pesanan
            $table->dateTime('order_date')->nullable()->after('shopee_order_id');
            
            // Kolom pengiriman
            $table->decimal('shipping_cost', 15, 2)->default(0.00)->after('shipping_provider');

            // Buat kolom spesifik Shopee menjadi nullable
            $table->string('shopee_order_id')->nullable()->change();
            $table->string('order_sn')->nullable()->change();
            $table->string('buyer_username')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['channel', 'customer_name', 'order_date', 'shipping_cost']);
            $table->dropConstrainedForeignId('reseller_id');

            // Kembalikan seperti semula jika perlu
            $table->string('shopee_order_id')->nullable(false)->change();
            $table->string('order_sn')->nullable(false)->change();
            $table->string('buyer_username')->nullable(false)->change();
        });
    }
};