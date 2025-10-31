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
Schema::create('service_fee_details', function (Blueprint $table) {
$table->id();
// Foreign key yang menghubungkan ke tabel service_fees
$table->foreignId('service_fee_id')->constrained()->onDelete('cascade');
$table->string('subcategory_name');
$table->text('description');
$table->timestamps();
});

// Kita juga akan membuat kolom deskripsi di tabel service_fees menjadi nullable
// karena tidak semua biaya (seperti biaya admin) akan punya deskripsi di tabel induk
Schema::table('service_fees', function (Blueprint $table) {
$table->text('description')->nullable()->change();
});
}

/**
* Reverse the migrations.
*/
public function down(): void
{
Schema::dropIfExists('service_fee_details');
Schema::table('service_fees', function (Blueprint $table) {
$table->text('description')->nullable(false)->change();
});
}
};