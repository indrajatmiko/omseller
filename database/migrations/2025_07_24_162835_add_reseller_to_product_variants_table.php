<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // dalam fungsi up()
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // Tambahkan kolom boolean, defaultnya false (0)
            $table->boolean('reseller')->default(false)->after('sku_type');
        });
    }

    // dalam fungsi down()
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('reseller');
        });
    }
};
