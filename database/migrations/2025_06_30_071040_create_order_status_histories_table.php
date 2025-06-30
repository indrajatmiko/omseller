<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();

            // Foreign key ke tabel 'orders'
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');

            // Kolom untuk data yang di-scrape
            $table->string('status');
            $table->text('description')->nullable();
            
            // Kolom khusus untuk tanggal pickup
            $table->timestamp('pickup_time')->nullable();

            // Kolom untuk waktu scrape (kapan perubahan ini tercatat)
            $table->timestamp('scrape_time');
            
            // timestamps() akan membuat created_at dan updated_at
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_status_histories');
    }
};