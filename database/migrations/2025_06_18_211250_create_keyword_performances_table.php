<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_performances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_report_id')->constrained()->onDelete('cascade');
            $table->string('kata_pencarian')->nullable();
            $table->string('tipe_pencocokan')->nullable();
            $table->string('per_klik')->nullable();
            $table->string('disarankan')->nullable();

            // Kolom dari metrics_data
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
            $table->string('peringkat_rata_rata_value')->nullable();
            $table->string('peringkat_rata_rata_delta')->nullable();
            // ... Tambahkan kolom lain jika diperlukan
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_performances');
    }
};