<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration ni untuk DB yang dah sedia ada (tak perlu migrate:fresh).
     * Tukar oer, ker, ffa, moisture, dirt jadi nullable - sebab nilai sebenar
     * di-key-in manual oleh Pegawai Kilang pada T+1 (esok pagi), bukan auto-kira.
     */
    public function up(): void
    {
        Schema::table('daily_operations', function (Blueprint $table) {
            $table->decimal('oer', 5, 2)->nullable()->change();
            $table->decimal('ker', 5, 2)->nullable()->change();
            $table->decimal('ffa', 5, 2)->nullable()->change();
            $table->decimal('moisture', 5, 2)->nullable()->change();
            $table->decimal('dirt', 5, 2)->nullable()->change();
            $table->decimal('throughput', 10, 2)->nullable()->change();
            $table->decimal('utilisation_rate', 5, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('daily_operations', function (Blueprint $table) {
            $table->decimal('oer', 5, 2)->default(0)->change();
            $table->decimal('ker', 5, 2)->default(0)->change();
            $table->decimal('ffa', 5, 2)->default(0)->change();
            $table->decimal('moisture', 5, 2)->default(0)->change();
            $table->decimal('dirt', 5, 2)->default(0)->change();
            $table->decimal('throughput', 10, 2)->default(0)->change();
            $table->decimal('utilisation_rate', 5, 2)->default(0)->change();
        });
    }
};
