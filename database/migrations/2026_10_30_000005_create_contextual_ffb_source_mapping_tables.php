<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('ffb_source_groups', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->string('heading');
            $normalizedHeading = $table->string('normalized_heading')->unique();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $normalizedHeading->charset('utf8mb4')->collation('utf8mb4_bin');
            }
            $table->enum('source_category', [
                'ppnj_owned',
                'ppnj_ppk_integrated',
                'external_supplier',
            ]);
            $table->foreignId('weighbridge_centre_id')->nullable()
                ->constrained('weighbridge_centres')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ffb_source_code_mappings', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->foreignId('ffb_source_group_id')->constrained('ffb_source_groups')->restrictOnDelete();
            $table->string('code');
            $normalizedCode = $table->string('normalized_code');
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $normalizedCode->charset('utf8mb4')->collation('utf8mb4_bin');
            }
            $table->foreignId('weighbridge_centre_id')->constrained('weighbridge_centres')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['ffb_source_group_id', 'normalized_code'], 'ffb_contextual_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ffb_source_code_mappings');
        Schema::dropIfExists('ffb_source_groups');
    }
};
