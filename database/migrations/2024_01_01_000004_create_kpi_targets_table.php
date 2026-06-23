<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_targets', function (Blueprint $table) {
            $table->id();
            // null = sasaran global (default), atau spesifik untuk satu kilang
            $table->foreignId('mill_id')->nullable()->constrained('mills')->cascadeOnDelete();
            $table->decimal('oer_target', 5, 2)->default(20.00);   // %
            $table->decimal('ker_target', 5, 2)->default(5.00);    // %
            $table->decimal('ffa_max', 5, 2)->default(5.00);       // %
            $table->decimal('downtime_max_hours', 4, 2)->default(2.00); // jam
            $table->year('effective_year');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_targets');
    }
};
