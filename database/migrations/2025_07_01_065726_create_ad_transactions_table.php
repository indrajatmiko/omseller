<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ad_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('transaction_hash', 64)->unique(); // Kunci unik kita
            $table->date('transaction_date');
            $table->string('transaction_type');
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ad_transactions');
    }
};