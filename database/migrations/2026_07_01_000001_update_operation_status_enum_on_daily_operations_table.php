<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_operations') || ! Schema::hasColumn('daily_operations', 'operation_status')) {
            return;
        }

        $columnType = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'daily_operations')
            ->where('column_name', 'operation_status')
            ->value('data_type');

        if ($columnType !== 'enum') {
            // If operation_status is VARCHAR (or other non-enum type), no schema change is required.
            return;
        }

        DB::statement("ALTER TABLE `daily_operations` MODIFY `operation_status` ENUM('Operasi','Tidak Operasi (Terima Buah Sahaja)') NOT NULL DEFAULT 'Operasi'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('daily_operations') || ! Schema::hasColumn('daily_operations', 'operation_status')) {
            return;
        }

        $columnType = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'daily_operations')
            ->where('column_name', 'operation_status')
            ->value('data_type');

        if ($columnType !== 'enum') {
            return;
        }

        DB::statement("ALTER TABLE `daily_operations` MODIFY `operation_status` ENUM('Operasi','Tidak Operasi') NOT NULL DEFAULT 'Operasi'");
    }
};
