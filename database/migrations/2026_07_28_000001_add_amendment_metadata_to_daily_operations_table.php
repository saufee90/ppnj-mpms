<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_operations')) {
            return;
        }

        Schema::table('daily_operations', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_operations', 'last_amendment_reason')) {
                $table->text('last_amendment_reason')->nullable()->after('catatan_tambahan');
            }

            if (! Schema::hasColumn('daily_operations', 'last_amended_by')) {
                $table->unsignedBigInteger('last_amended_by')->nullable()->after('last_amendment_reason');
            }

            if (! Schema::hasColumn('daily_operations', 'last_amended_at')) {
                $table->timestamp('last_amended_at')->nullable()->after('last_amended_by');
            }
        });

        if (Schema::hasColumn('daily_operations', 'last_amended_by') && DB::getDriverName() !== 'sqlite') {
            Schema::table('daily_operations', function (Blueprint $table) {
                $table->foreign('last_amended_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('daily_operations')) {
            return;
        }

        if (Schema::hasColumn('daily_operations', 'last_amended_by') && DB::getDriverName() !== 'sqlite') {
            Schema::table('daily_operations', function (Blueprint $table) {
                $table->dropForeign(['last_amended_by']);
            });
        }

        Schema::table('daily_operations', function (Blueprint $table) {
            $columnsToDrop = [];

            foreach (['last_amendment_reason', 'last_amended_by', 'last_amended_at'] as $column) {
                if (Schema::hasColumn('daily_operations', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
