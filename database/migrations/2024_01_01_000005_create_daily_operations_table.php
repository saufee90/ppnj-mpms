<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_operations', function (Blueprint $table) {
            $table->id();

            // A. Maklumat Asas
            $table->date('tarikh');
            $table->foreignId('mill_id')->constrained('mills');
            $table->enum('shift', ['Shift 1', 'Shift 2', 'Shift 3', 'Harian'])->default('Harian');
            $table->foreignId('officer_id')->constrained('users'); // pegawai yang mengisi data

            // B. Penerimaan & Pemprosesan (BTS - Buah Tandan Segar)
            $table->decimal('bts_diterima', 10, 2)->default(0);   // MT - BTS diterima
            $table->decimal('bts_diproses', 10, 2)->default(0);   // MT - BTS diproses
            $table->decimal('baki_stok_bts', 10, 2)->default(0);  // MT - baki stok BTS
            $table->decimal('jam_operasi', 5, 2)->default(0);     // jam
            $table->decimal('downtime_jam', 5, 2)->default(0);    // jam
            $table->text('sebab_downtime')->nullable();

            // C. Pengeluaran
            $table->decimal('pengeluaran_cpo', 10, 2)->default(0); // MT
            $table->decimal('pengeluaran_pk', 10, 2)->default(0);  // MT - kernel
            $table->decimal('stok_cpo', 10, 2)->default(0);        // MT
            $table->decimal('stok_pk', 10, 2)->default(0);         // MT

            // D. Kualiti & KPI (dikira automatik, disimpan untuk laporan pantas)
            $table->decimal('oer', 5, 2)->default(0);          // %
            $table->decimal('ker', 5, 2)->default(0);          // %
            $table->decimal('ffa', 5, 2)->default(0);          // %
            $table->decimal('moisture', 5, 2)->default(0);     // %
            $table->decimal('dirt', 5, 2)->default(0);         // %
            $table->decimal('throughput', 10, 2)->default(0);  // MT/jam
            $table->decimal('utilisation_rate', 5, 2)->default(0); // %

            // E. Catatan
            $table->text('isu_operasi')->nullable();
            $table->text('tindakan_pembetulan')->nullable();
            $table->text('catatan_tambahan')->nullable();

            // Status & meta
            $table->enum('status', ['draft', 'submitted', 'verified'])->default('submitted');
            $table->timestamps();

            // Data tidak boleh duplicate untuk tarikh + kilang + shift yang sama
            $table->unique(['tarikh', 'mill_id', 'shift'], 'unique_tarikh_mill_shift');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_operations');
    }
};
