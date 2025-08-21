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
    Schema::create('location_data', function (Blueprint $table) {
        $table->id();
        $table->foreignId('buyer_profile_id')->constrained()->onDelete('cascade')->unique();
        $table->string('city')->nullable()->comment('Kota atau Kabupaten');
        $table->string('district')->nullable()->comment('Kecamatan');
        $table->string('province')->nullable()->comment('Provinsi');
        $table->string('country_code', 10)->nullable()->comment('Kode Negara, misal: ID');
        $table->string('zip_code', 10)->nullable()->comment('Kode Pos');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_data');
    }
};
