<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_profiles', function (Blueprint $table) {
            $table->id();
            // Penting: Siapa penjual yang memiliki profil ini (Multi-tenancy)
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); 
            
            // Pengenal unik pembeli
            $table->string('buyer_username');
            $table->string('address_identifier'); // Hash dari alamat untuk pencarian cepat

            // Data yang kita simpan
            $table->string('buyer_real_name');
            $table->timestamps();

            // Index unik untuk memastikan 1 profil per pembeli per penjual
            // dan untuk pencarian super cepat!
            $table->unique(['user_id', 'buyer_username', 'address_identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_profiles');
    }
};