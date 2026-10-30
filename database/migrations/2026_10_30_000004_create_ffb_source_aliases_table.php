<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ffb_source_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weighbridge_centre_id')->constrained('weighbridge_centres')->restrictOnDelete();
            $table->string('alias_code');
            $table->string('normalized_code')->charset('utf8mb4')->collation('utf8mb4_bin')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['weighbridge_centre_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ffb_source_aliases');
    }
};
