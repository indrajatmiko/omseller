<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Kolom untuk kategori, bisa null jika produk belum dikategorikan
            $table->foreignId('category_id')->nullable()->after('user_id')->constrained('product_categories')->onDelete('set null');
            // Kolom status, 'active' = tampil, 'draft' = tidak tampil
            $table->enum('status', ['active', 'hide', 'draft'])->default('active')->after('product_name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
            $table->dropColumn('status');
        });
    }
};
