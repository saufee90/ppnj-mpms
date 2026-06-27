<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('daily_operations', function (Blueprint $table) {
            $table->decimal('stok_cpo_yesterday', 10, 2)->nullable()->after('stok_pk');
            $table->decimal('stok_pk_yesterday', 10, 2)->nullable()->after('stok_cpo_yesterday');
        });
    }

    public function down()
    {
        Schema::table('daily_operations', function (Blueprint $table) {
            $table->dropColumn(['stok_cpo_yesterday', 'stok_pk_yesterday']);
        });
    }
};
