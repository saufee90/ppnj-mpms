<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_indicator_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mill_id')->nullable()->constrained('mills')->nullOnDelete();
            $table->year('year');
            $table->string('indicator_code', 100);
            $table->string('indicator_name', 255);
            $table->string('unit', 20)->nullable();
            $table->enum('evaluation_direction', ['higher_is_better', 'lower_is_better']);
            $table->decimal('green_threshold', 12, 2)->nullable();
            $table->decimal('red_threshold', 12, 2)->nullable();
            $table->decimal('period_target', 14, 2)->nullable();
            $table->json('monthly_targets')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['mill_id', 'year', 'indicator_code', 'is_active'], 'kpi_indicator_scope_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_indicator_settings');
    }
};
