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
        Schema::create('service_fees', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->default('shopee')->comment('Contoh: shopee, tiktok, tokopedia');
            $table->string('seller_type')->comment('Contoh: non_star, mall, star_seller');
            $table->string('fee_type')->comment('Contoh: admin_fee, program_fee');
            $table->string('name')->comment('Nama biaya, cth: Kategori A, Gratis Ongkir Xtra');
            $table->text('description')->nullable()->comment('Deskripsi atau rincian kategori');
            $table->decimal('value', 8, 2)->comment('Nilai biaya, bisa persen atau nominal');
            $table->string('value_type')->default('percentage')->comment('Tipe nilai: percentage atau fixed');
            $table->unsignedInteger('max_cap')->nullable()->comment('Batas maksimal potongan biaya dalam Rupiah');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_fees');
    }
};