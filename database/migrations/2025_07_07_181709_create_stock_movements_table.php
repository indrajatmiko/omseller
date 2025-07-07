<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            // User pemilik data
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // Varian produk yang stoknya bergerak
            $table->foreignId('product_variant_id')->constrained()->onDelete('cascade');
            // Order yang menyebabkan pergerakan (opsional, bisa null untuk penyesuaian manual)
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            
            // Jenis pergerakan: sale, return, adjustment, initial_stock
            $table->string('type'); 
            
            // Jumlah. Positif untuk penambahan, negatif untuk pengurangan.
            $table->integer('quantity'); 
            
            // Catatan untuk penyesuaian manual
            $table->text('notes')->nullable(); 
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};