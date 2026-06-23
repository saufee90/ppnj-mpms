<?php

namespace Database\Seeders;

use App\Models\Mill;
use Illuminate\Database\Seeder;

class MillSeeder extends Seeder
{
    public function run(): void
    {
        $mills = [
            [
                'name' => 'Kilang Sawit Bukit Bujang',
                'code' => 'BBJ',
                'location' => 'Segamat, Johor',
                'is_active' => true,
            ],
            [
                'name' => 'Kilang Sawit PPNJ Kahang',
                'code' => 'KHG',
                'location' => 'Kluang, Johor',
                'is_active' => true,
            ],
        ];

        foreach ($mills as $mill) {
            Mill::firstOrCreate(['code' => $mill['code']], $mill);
        }
    }
}
