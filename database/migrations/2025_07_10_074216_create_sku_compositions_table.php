<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_sku_compositions_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_compositions', function (Blueprint $table) {
            $table->id();
            // SKU Induk/Gabungan
            $table->string('bundle_sku');
            // SKU Komponen/Anak
            $table->string('component_sku');
            // Jumlah komponen yang dibutuhkan untuk 1 unit SKU Gabungan
            $table->integer('quantity');
            
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->unique(['bundle_sku', 'component_sku', 'user_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_compositions');
    }
};