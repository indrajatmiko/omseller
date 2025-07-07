<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // Harga beli produk dari supplier (Cost of Goods Sold)
            $table->decimal('cost_price', 15, 2)->nullable()->after('promo_price');
            
            // Stok fisik aktual di gudang
            $table->integer('warehouse_stock')->default(0)->after('cost_price');
            
            // Stok yang sudah dipesan tapi belum dikirim (opsional tapi direkomendasikan)
            $table->integer('reserved_stock')->default(0)->after('warehouse_stock');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'warehouse_stock', 'reserved_stock']);
        });
    }
};