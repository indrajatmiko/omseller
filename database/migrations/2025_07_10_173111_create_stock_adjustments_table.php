<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_stock_adjustments_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            // Oleh siapa: Menghubungkan ke user yang melakukan perubahan
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // SKU apa yang diubah
            $table->string('variant_sku')->index();
            // Opsional: Menghubungkan ke varian spesifik (berguna untuk referensi)
            $table->foreignId('product_variant_id')->nullable()->constrained()->onDelete('set null');
            // Stok sebelum penyesuaian
            $table->integer('stock_before');
            // Jumlah yang ditambah/dikurangi (bisa positif/negatif)
            $table->integer('quantity_adjusted');
            // Stok setelah penyesuaian
            $table->integer('stock_after');
            // Catatan tambahan jika diperlukan
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};