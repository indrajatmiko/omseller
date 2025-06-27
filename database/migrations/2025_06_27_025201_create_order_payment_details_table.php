<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderPaymentDetailsTable extends Migration
{
    public function up()
    {
        Schema::create('order_payment_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->string('label');
            $table->decimal('amount', 15, 2);
            $table->string('type')->nullable()->comment('Contoh: subtotal, shipping, fee, income');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_payment_details');
    }
}