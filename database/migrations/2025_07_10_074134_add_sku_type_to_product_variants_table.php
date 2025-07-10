<?php

// database/migrations/xxxx_xx_xx_xxxxxx_add_sku_type_to_product_variants_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->enum('sku_type', ['mandiri', 'gabungan'])->default('mandiri')->after('variant_sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('sku_type');
        });
    }
};