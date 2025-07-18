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
        Schema::create('gmv_performance_details', function (Blueprint $table) {
            $table->id();
            // Foreign key untuk menghubungkan ke laporan utama
            $table->foreignId('campaign_report_id')->constrained('campaign_reports')->onDelete('cascade');
            
            // Kolom dari 'fixed' table
            $table->string('kata_pencarian')->nullable();
            $table->string('penempatan_rekomendasi')->nullable();
            $table->string('harga_bid')->nullable();

            // Kolom metrik dari 'main' table (value + delta)
            $table->string('iklan_dilihat_value')->nullable();
            $table->string('iklan_dilihat_delta')->nullable();
            $table->string('jumlah_klik_value')->nullable();
            $table->string('jumlah_klik_delta')->nullable();
            $table->string('persentase_klik_value')->nullable();
            $table->string('persentase_klik_delta')->nullable();
            $table->string('biaya_iklan_value')->nullable();
            $table->string('biaya_iklan_delta')->nullable();
            $table->string('penjualan_dari_iklan_value')->nullable();
            $table->string('penjualan_dari_iklan_delta')->nullable();
            $table->string('konversi_value')->nullable();
            $table->string('konversi_delta')->nullable();
            $table->string('produk_terjual_value')->nullable();
            $table->string('produk_terjual_delta')->nullable();
            $table->string('roas_value')->nullable();
            $table->string('roas_delta')->nullable();
            $table->string('acos_value')->nullable();
            $table->string('acos_delta')->nullable();
            $table->string('tingkat_konversi_value')->nullable();
            $table->string('tingkat_konversi_delta')->nullable();
            $table->string('biaya_per_konversi_value')->nullable();
            $table->string('biaya_per_konversi_delta')->nullable();
            $table->string('konversi_langsung_value')->nullable();
            $table->string('konversi_langsung_delta')->nullable();
            $table->string('produk_terjual_langsung_value')->nullable();
            $table->string('produk_terjual_langsung_delta')->nullable();
            $table->string('penjualan_dari_iklan_langsung_value')->nullable();
            $table->string('penjualan_dari_iklan_langsung_delta')->nullable();
            $table->string('roas_langsung_value')->nullable();
            $table->string('roas_langsung_delta')->nullable();
            $table->string('acos_langsung_value')->nullable();
            $table->string('acos_langsung_delta')->nullable();
            $table->string('tingkat_konversi_langsung_value')->nullable();
            $table->string('tingkat_konversi_langsung_delta')->nullable();
            $table->string('biaya_per_konversi_langsung_value')->nullable();
            $table->string('biaya_per_konversi_langsung_delta')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gmv_performance_details');
    }
};