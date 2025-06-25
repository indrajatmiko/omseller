<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('shopee_order_id')->unique();
            $table->string('order_sn');
            $table->string('buyer_username')->nullable();
            $table->decimal('total_price', 15, 2);
            $table->string('payment_method')->nullable();
            $table->string('order_status')->nullable();
            $table->text('status_description')->nullable();
            $table->string('shipping_provider')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('order_detail_url');
            $table->timestamp('scraped_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('orders');
    }
}