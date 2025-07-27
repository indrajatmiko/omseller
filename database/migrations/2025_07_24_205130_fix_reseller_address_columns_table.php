<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            // Hapus foreign key constraint dan kolom lama (jika ada)
            if (Schema::hasColumn('resellers', 'province_id')) {
                $table->dropForeign(['province_id']);
                $table->dropColumn('province_id');
            }
            if (Schema::hasColumn('resellers', 'city_id')) {
                $table->dropForeign(['city_id']);
                $table->dropColumn('city_id');
            }
             if (Schema::hasColumn('resellers', 'district_id')) {
                $table->dropForeign(['district_id']);
                $table->dropColumn('district_id');
            }

            // Tambahkan kolom baru yang benar dengan tipe data yang sesuai
            $table->char('province_code', 2)->nullable()->after('email');
            $table->char('city_code', 4)->nullable()->after('province_code');
            $table->char('district_code', 7)->nullable()->after('city_code');

            // Tambahkan foreign key constraint yang benar
            $table->foreign('province_code')->references('code')->on('indonesia_provinces')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('city_code')->references('code')->on('indonesia_cities')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('district_code')->references('code')->on('indonesia_districts')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            // Rollback: Hapus kolom baru
            $table->dropForeign(['province_code']);
            $table->dropForeign(['city_code']);
            $table->dropForeign(['district_code']);
            $table->dropColumn(['province_code', 'city_code', 'district_code']);
            
            // Rollback: Kembalikan kolom lama (opsional, tergantung kebutuhan rollback Anda)
            // $table->foreignId('province_id')->nullable();
            // $table->foreignId('city_id')->nullable();
            // $table->foreignId('district_id')->nullable();
        });
    }
};