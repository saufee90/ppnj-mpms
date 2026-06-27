<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_operations', function (Blueprint $table) {
            $table->decimal('produksi_cpo', 10, 2)->nullable();
            $table->decimal('produksi_pk', 10, 2)->nullable();
            $table->decimal('baki_bts_semalam', 10, 2)->nullable();
            $table->decimal('baki_bts_selepas_diproses', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_operations', function (Blueprint $table) {
            $table->dropColumn(['produksi_cpo', 'produksi_pk', 'baki_bts_semalam', 'baki_bts_selepas_diproses']);
        });
    }
};
