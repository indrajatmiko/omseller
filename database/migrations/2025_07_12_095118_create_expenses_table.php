<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_expenses_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2); // Jumlah pengeluaran
            $table->text('description')->nullable(); // Catatan/deskripsi
            $table->date('transaction_date'); // Tanggal pengeluaran
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};