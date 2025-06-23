<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('shopee_product_id');
            $table->string('product_name', 512);
            $table->string('parent_sku')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->integer('total_sales')->default(0);
            $table->integer('total_stock')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'shopee_product_id']); // Kunci unik
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};