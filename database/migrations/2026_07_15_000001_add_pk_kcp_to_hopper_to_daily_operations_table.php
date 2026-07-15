<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_operations') || Schema::hasColumn('daily_operations', 'pk_kcp_to_hopper')) {
            return;
        }

        Schema::table('daily_operations', function (Blueprint $table) {
            $table->decimal('pk_kcp_to_hopper', 10, 2)->default(0.00)->after('stok_pk');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('daily_operations') || ! Schema::hasColumn('daily_operations', 'pk_kcp_to_hopper')) {
            return;
        }

        Schema::table('daily_operations', function (Blueprint $table) {
            $table->dropColumn('pk_kcp_to_hopper');
        });
    }
};