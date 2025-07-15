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
        Schema::table('buyer_profiles', function (Blueprint $table) {
            $table->text('buyer_address')->nullable()->after('buyer_real_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buyer_profiles', function (Blueprint $table) {
            $table->dropColumn('buyer_address');
        });
    }
};
