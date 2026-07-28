<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SystemSetting::DEFAULTS as $key => $value) {
            SystemSetting::setValue($key, $value);
        }
    }
}
