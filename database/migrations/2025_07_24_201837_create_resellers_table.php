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

            // Kolom untuk dependent dropdown (menggunakan ID dari package laravolt)
            $table->foreignId('province_id')->nullable()->constrained('indonesia_provinces');
            $table->foreignId('city_id')->nullable()->constrained('indonesia_cities');
            $table->foreignId('district_id')->nullable()->constrained('indonesia_districts');
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