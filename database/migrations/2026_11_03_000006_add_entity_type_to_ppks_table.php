<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppks', function (Blueprint $table) {
            $table->enum('entity_type', ['official_ppk', 'independent_integrated_estate'])
                ->default('official_ppk')->after('name');
        });
    }

    public function down(): void
    {
        if (DB::table('ppks')->where('entity_type', '!=', 'official_ppk')->exists()) {
            throw new RuntimeException('Independent FFB grouping entities exist; removing their type distinction is unsafe.');
        }

        Schema::table('ppks', function (Blueprint $table) {
            $table->dropColumn('entity_type');
        });
    }
};
