<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_operations')) {
            return;
        }

        if (! Schema::hasColumn('daily_operations', 'operation_status')) {
            Schema::table('daily_operations', function (Blueprint $table) {
                $table->enum('operation_status', [
                    'Operasi',
                    'Tidak Operasi (Terima Buah Sahaja)',
                ])->default('Operasi');
            });
        }

        if (! Schema::hasColumn('daily_operations', 'operation_note')) {
            Schema::table('daily_operations', function (Blueprint $table) {
                $table->text('operation_note')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally retain both columns: their original ownership cannot be
        // established on rollback, and dropping either could destroy existing data.
    }
};
