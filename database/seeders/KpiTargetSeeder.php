<?php

namespace Database\Seeders;

use App\Models\KpiTarget;
use Illuminate\Database\Seeder;

class KpiTargetSeeder extends Seeder
{
    public function run(): void
    {
        // Sasaran KPI global (mill_id = null) untuk tahun semasa
        KpiTarget::firstOrCreate(
            ['mill_id' => null, 'effective_year' => now()->year],
            [
                'oer_target' => 20.00,
                'ker_target' => 5.00,
                'ffa_max' => 5.00,
                'downtime_max_hours' => 2.00,
                'is_active' => true,
            ]
        );
    }
}
