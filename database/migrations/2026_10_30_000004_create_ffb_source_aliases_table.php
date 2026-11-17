<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('ffb_source_aliases', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->foreignId('weighbridge_centre_id')->constrained('weighbridge_centres')->restrictOnDelete();
            $table->string('alias_code');
            $normalizedCode = $table->string('normalized_code')->unique();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $normalizedCode->charset('utf8mb4')->collation('utf8mb4_bin');
            }
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
