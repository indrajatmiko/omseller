<?php

// database/migrations/xxxx_xx_xx_xxxxxx_add_stock_restored_to_orders_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Kolom untuk menandai apakah stok dari pesanan ini sudah dikembalikan.
            // Default `false`. Akan diubah menjadi `true` setelah stok berhasil dikembalikan.
            $table->boolean('is_stock_restored')->default(false)->after('status_description');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_stock_restored');
        });
    }
};