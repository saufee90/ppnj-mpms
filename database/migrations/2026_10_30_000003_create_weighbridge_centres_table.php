<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weighbridge_centres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ppk_id')->nullable()->constrained('ppks')->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('source_category', [
                'ppnj_owned',
                'ppnj_ppk_integrated',
                'external_supplier',
            ]);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['ppk_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weighbridge_centres');
    }
};
