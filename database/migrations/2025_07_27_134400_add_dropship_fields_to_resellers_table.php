<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->boolean('is_dropship')->default(false)->after('discount_percentage');
            $table->string('dropship_name')->nullable()->after('is_dropship');
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn(['is_dropship', 'dropship_name']);
        });
    }
};