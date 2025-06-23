<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('shopee_variant_id');
            $table->string('variant_name');
            $table->string('variant_sku')->nullable();
            $table->string('price')->nullable();
            $table->string('promo_price')->nullable();
            $table->string('stock'); // String untuk menangani 'Habis'
            $table->timestamps();

            $table->unique(['product_id', 'shopee_variant_id']); // Kunci unik
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};