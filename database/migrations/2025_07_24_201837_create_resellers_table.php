<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // Kolom baru: kode wilayah, bukan ID
            $table->char('province_code', 2)->nullable()->after('email');
            $table->char('city_code', 4)->nullable()->after('province_code');
            $table->char('district_code', 7)->nullable()->after('city_code');

            // Foreign key constraint
            $table->foreign('province_code')->references('code')->on('indonesia_provinces')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('city_code')->references('code')->on('indonesia_cities')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('district_code')->references('code')->on('indonesia_districts')->onUpdate('cascade')->onDelete('set null');

            // Alamat lengkap
            $table->text('address')->nullable();
            
            // Kolom untuk diskon (sesuai permintaan)
            $table->decimal('discount_percentage', 5, 2)->default(0.00);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resellers');
    }
};