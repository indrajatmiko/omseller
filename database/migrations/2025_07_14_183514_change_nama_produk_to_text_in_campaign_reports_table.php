<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('campaign_reports', function (Blueprint $table) {
            // Mengubah tipe kolom 'nama_produk' dari VARCHAR menjadi TEXT
            $table->text('nama_produk')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaign_reports', function (Blueprint $table) {
            // Mengembalikan ke tipe semula jika migrasi di-rollback
            // Default string adalah VARCHAR(255)
            $table->string('nama_produk', 255)->nullable()->change();
        });
    }
};