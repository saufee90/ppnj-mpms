<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_operations', function (Blueprint $table) {
            $table->enum('operation_status', ['operasi', 'tidak_operasi_terima_bts'])
                ->default('operasi')
                ->after('shift');
            $table->text('operation_note')->nullable()->after('operation_status');
        });
    }

    public function down(): void
    {
        Schema::table('daily_operations', function (Blueprint $table) {
            $table->dropColumn(['operation_note', 'operation_status']);
        });
    }
};
