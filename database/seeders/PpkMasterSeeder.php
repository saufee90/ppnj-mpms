<?php

namespace Database\Seeders;

use App\Models\Ppk;
use Illuminate\Database\Seeder;

class PpkMasterSeeder extends Seeder
{
    public function run(): void
    {
        $ppks = [
            ['code' => 'PPK-BATU-ANAM', 'name' => 'PPK Batu Anam'],
            ['code' => 'PPK-SEGAMAT', 'name' => 'PPK Segamat'],
            ['code' => 'PPK-LABIS', 'name' => 'PPK Labis'],
            ['code' => 'PPK-BUKIT-GAMBIR', 'name' => 'PPK Bukit Gambir'],
            ['code' => 'PPK-LEDANG-TANGKAK', 'name' => 'PPK Ledang Tangkak'],
            ['code' => 'PPK-PAGOH', 'name' => 'PPK Pagoh'],
            ['code' => 'PPK-BAKRI', 'name' => 'PPK Bakri'],
            ['code' => 'PPK-MUAR-SELATAN', 'name' => 'PPK Muar Selatan'],
            ['code' => 'PPK-PARIT-SULONG', 'name' => 'PPK Parit Sulong'],
            ['code' => 'PPK-SIMPANG-LIMA', 'name' => 'PPK Simpang Lima'],
            ['code' => 'PPK-SENGGARANG', 'name' => 'PPK Senggarang'],
            ['code' => 'PPK-PARIT-RAJA', 'name' => 'PPK Parit Raja'],
            ['code' => 'PPK-BATU-PAHAT-TENGAH', 'name' => 'PPK Batu Pahat Tengah'],
            ['code' => 'PPK-SRI-MEDAN', 'name' => 'PPK Sri Medan'],
            ['code' => 'PPK-RENGIT', 'name' => 'PPK Rengit'],
            ['code' => 'PPK-KLUANG', 'name' => 'PPK Kluang'],
            ['code' => 'PPK-SIMPANG-RENGGAM', 'name' => 'PPK Simpang Renggam'],
            ['code' => 'PPK-BENUT', 'name' => 'PPK Benut'],
            ['code' => 'PPK-AYER-BALOI', 'name' => 'PPK Ayer Baloi'],
            ['code' => 'PPK-PEKAN-NANAS', 'name' => 'PPK Pekan Nanas'],
            ['code' => 'PPK-PONTIAN-SELATAN', 'name' => 'PPK Pontian Selatan'],
            ['code' => 'PPK-KULAI', 'name' => 'PPK Kulai'],
            ['code' => 'PPK-JOHOR-BAHRU-TIMUR', 'name' => 'PPK Johor Bahru Timur'],
            ['code' => 'PPK-JOHOR-BAHRU-SELATAN', 'name' => 'PPK Johor Bahru Selatan'],
            ['code' => 'PPK-KOTA-TINGGI', 'name' => 'PPK Kota Tinggi'],
            ['code' => 'PPK-KOTA-TINGGI-TIMUR', 'name' => 'PPK Kota Tinggi Timur'],
            ['code' => 'PPK-PENGERANG', 'name' => 'PPK Pengerang'],
            ['code' => 'PPK-MERSING', 'name' => 'PPK Mersing'],
            ['code' => 'PPK-ENDAU', 'name' => 'PPK Endau'],
        ];

        foreach ($ppks as $ppk) {
            Ppk::updateOrCreate(
                ['code' => $ppk['code']],
                ['name' => $ppk['name'], 'is_active' => true],
            );
        }
    }
}
