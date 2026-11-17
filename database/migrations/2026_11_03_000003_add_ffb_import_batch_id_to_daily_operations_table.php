<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_operations', function (Blueprint $table) {
            $table->foreignId('ffb_import_batch_id')->nullable()->unique()
                ->constrained('ffb_import_batches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('daily_operations', function (Blueprint $table) {
            $table->dropForeign(['ffb_import_batch_id']);
            $table->dropUnique(['ffb_import_batch_id']);
            $table->dropColumn('ffb_import_batch_id');
        });
    }
};
