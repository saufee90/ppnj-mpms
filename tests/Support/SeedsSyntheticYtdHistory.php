<?php

namespace Tests\Support;

use App\Models\Mill;
use App\Models\MillMonthlyHistory;

trait SeedsSyntheticYtdHistory
{
    protected function seedSyntheticYtdHistory(Mill $mill): void
    {
        for ($month = 1; $month <= 6; $month++) {
            MillMonthlyHistory::create([
                'mill_id' => $mill->id,
                'year' => 2026,
                'month' => $month,
                'bts_diterima' => '0.00',
                'bts_diproses' => '0.00',
                'jam_operasi' => '0.00',
                'pengeluaran_cpo' => '0.00',
                'jualan_cpo' => '0.00',
                'pengeluaran_pk' => '0.00',
                'jualan_pk' => '0.00',
            ]);
        }
    }
}
