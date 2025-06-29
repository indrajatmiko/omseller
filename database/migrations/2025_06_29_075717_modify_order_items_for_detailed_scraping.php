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
        Schema::table('order_items', function (Blueprint $table) {
            // 1. Menghapus kolom lama yang tidak digunakan lagi
            $table->dropColumn(['variant_description', 'image_url']);

            // 2. Menambahkan kolom baru
            // Menambahkan 'variant_sku' setelah 'product_name' untuk kerapian
            $table->string('variant_sku')->nullable()->after('product_name');

            // Menambahkan 'price' setelah 'variant_sku'
            $table->unsignedBigInteger('price')->default(0)->after('variant_sku');
            
            // Menambahkan 'subtotal' setelah 'quantity'
            $table->unsignedBigInteger('subtotal')->default(0)->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('order_items', function (Blueprint $table) {
            // 1. Menghapus kolom yang ditambahkan di 'up()'
            $table->dropColumn(['variant_sku', 'price', 'subtotal']);

            // 2. Mengembalikan kolom lama jika migrasi di-rollback
            $table->text('variant_description')->nullable();
            $table->string('image_url')->nullable();
        });
    }
};