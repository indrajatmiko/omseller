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
            $table->foreignId('order_id')->unique()->constrained()->onDelete('cascade');

            // Rincian Harga Produk
            $table->decimal('product_subtotal', 15, 2)->nullable();

            // Rincian Ongkos Kirim
            $table->decimal('shipping_fee_paid_by_buyer', 15, 2)->nullable();
            $table->decimal('shipping_fee_paid_to_logistic', 15, 2)->nullable();
            $table->decimal('shopee_shipping_subsidy', 15, 2)->nullable();

            // Rincian Biaya Lainnya / Potongan
            $table->decimal('admin_fee', 15, 2)->nullable();
            $table->decimal('service_fee', 15, 2)->nullable();
            
            // (Baru) Tambahkan kolom untuk berbagai jenis biaya/potongan lain jika ada
            $table->decimal('coins_spent_by_buyer', 15, 2)->nullable();
            $table->decimal('seller_voucher', 15, 2)->nullable();

            // Total yang dihitung
            $table->decimal('total_income', 15, 2)->nullable()->comment('Total penghasilan dari rincian ini');
            
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_payment_details');
    }
}