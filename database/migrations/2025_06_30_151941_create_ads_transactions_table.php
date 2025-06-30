<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('ads_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('transaction_time');
            $table->string('transaction_type');
            $table->bigInteger('amount'); // Simpan sebagai integer (misal: 100000)
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
    public function down() {
        Schema::dropIfExists('ads_transactions');
    }
};