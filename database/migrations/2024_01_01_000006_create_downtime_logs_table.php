<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downtime_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_operation_id')->constrained('daily_operations')->cascadeOnDelete();
            $table->foreignId('mill_id')->constrained('mills');
            $table->date('tarikh');
            $table->time('masa_mula')->nullable();
            $table->time('masa_tamat')->nullable();
            $table->decimal('tempoh_jam', 5, 2)->default(0);
            $table->string('kategori')->nullable(); // contoh: Mekanikal, Elektrikal, Kekurangan BTS, dll
            $table->text('sebab');
            $table->text('tindakan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downtime_logs');
    }
};
