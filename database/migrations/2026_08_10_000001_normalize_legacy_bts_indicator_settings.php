<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kpi_indicator_settings')) {
            return;
        }

        DB::table('kpi_indicator_settings')
            ->where('indicator_code', 'total_bts_diterima')
            ->orderBy('id')
            ->get()
            ->each(function (object $legacySetting): void {
                $unifiedSettingExists = DB::table('kpi_indicator_settings')
                    ->where('mill_id', $legacySetting->mill_id)
                    ->where('year', $legacySetting->year)
                    ->where('indicator_code', 'bts_diterima_dan_diproses')
                    ->exists();

                if ($unifiedSettingExists) {
                    return;
                }

                DB::table('kpi_indicator_settings')
                    ->where('id', $legacySetting->id)
                    ->update([
                        'indicator_code' => 'bts_diterima_dan_diproses',
                        'indicator_name' => 'Penerimaan & Pemprosesan BTS',
                    ]);
            });
    }

    public function down(): void
    {
        // Data migration is intentionally not reversed to avoid changing new unified settings.
    }
};