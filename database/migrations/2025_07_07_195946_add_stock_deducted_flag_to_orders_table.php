<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Kolom ini akan menjadi penanda apakah stok untuk order ini sudah dikurangi.
            // Sangat penting untuk mencegah pengurangan ganda.
            $table->boolean('is_stock_deducted')->default(false)->after('final_income');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_stock_deducted');
        });
    }
};