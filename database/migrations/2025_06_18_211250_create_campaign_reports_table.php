<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('campaign_id');
            $table->date('scrape_date');
            $table->string('date_range_text')->nullable();

            // Kolom dari productInfo
            $table->string('nama_produk')->nullable();
            $table->text('gambar_url')->nullable();
            $table->string('status_iklan')->nullable();
            $table->string('modal')->nullable();
            $table->string('periode_iklan')->nullable();
            $table->string('penempatan_iklan')->nullable();
            $table->string('mode_bidding')->nullable();
            $table->string('bidding_dinamis')->nullable();
            $table->string('target_roas')->nullable();

            // Kolom dari performanceMetrics
            $table->string('dilihat')->nullable();
            $table->string('klik')->nullable();
            $table->string('persentase_klik')->nullable();
            $table->string('biaya')->nullable();
            $table->string('pesanan')->nullable();
            $table->string('produk_terjual')->nullable();
            $table->string('omzet_iklan')->nullable();
            $table->string('efektivitas_iklan')->nullable(); // ROAS
            $table->string('cir')->nullable(); // ACOS

            $table->timestamps();

            // Kunci unik untuk mencegah duplikasi
            $table->unique(['user_id', 'campaign_id', 'scrape_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_reports');
    }
};