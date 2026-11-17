<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mill_monthly_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mill_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            foreach (['bts_diterima', 'bts_diproses', 'jam_operasi', 'pengeluaran_cpo', 'jualan_cpo', 'pengeluaran_pk', 'jualan_pk'] as $field) {
                $table->decimal($field, 16, 2);
            }
            $table->timestamps();
            $table->unique(['mill_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mill_monthly_histories');
    }
};
