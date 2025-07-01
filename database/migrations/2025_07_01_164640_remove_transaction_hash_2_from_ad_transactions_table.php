<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
// dalam method up()
public function up()
{
    Schema::table('ad_transactions', function (Blueprint $table) {
        $table->dropColumn('transaction_hash');
    });
}

// dalam method down()
public function down()
{
    Schema::table('ad_transactions', function (Blueprint $table) {
        $table->string('transaction_hash', 64)->after('user_id')->nullable();
        // Jika sebelumnya ada unique index, tambahkan kembali di sini.
        // $table->unique(['user_id', 'transaction_hash']); 
    });
}
};
