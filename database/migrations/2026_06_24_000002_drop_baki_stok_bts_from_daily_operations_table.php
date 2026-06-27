<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_operations', function (Blueprint $table) {
            $table->dropColumn('baki_stok_bts');
        });
    }

    public function down(): void
    {
        Schema::table('daily_operations', function (Blueprint $table) {
            $table->decimal('baki_stok_bts', 10, 2)->default(0)->after('bts_diproses');
        });
    }
};
