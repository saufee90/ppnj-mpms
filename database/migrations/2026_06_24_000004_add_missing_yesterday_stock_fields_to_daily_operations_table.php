<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('daily_operations', 'stok_cpo_yesterday')) {
            Schema::table('daily_operations', function (Blueprint $table) {
                $table->decimal('stok_cpo_yesterday', 10, 2)->nullable()->after('stok_pk');
            });
        }

        if (! Schema::hasColumn('daily_operations', 'stok_pk_yesterday')) {
            Schema::table('daily_operations', function (Blueprint $table) {
                $table->decimal('stok_pk_yesterday', 10, 2)->nullable()->after('stok_cpo_yesterday');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('daily_operations', 'stok_pk_yesterday')) {
            Schema::table('daily_operations', function (Blueprint $table) {
                $table->dropColumn('stok_pk_yesterday');
            });
        }

        if (Schema::hasColumn('daily_operations', 'stok_cpo_yesterday')) {
            Schema::table('daily_operations', function (Blueprint $table) {
                $table->dropColumn('stok_cpo_yesterday');
            });
        }
    }
};
