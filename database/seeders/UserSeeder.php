<?php

namespace Database\Seeders;

use App\Models\Mill;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->first();
        $pengurusanRole = Role::where('name', 'pengurusan')->first();
        $pegawaiRole = Role::where('name', 'pegawai_kilang')->first();

        $bbj = Mill::where('code', 'BBJ')->first();
        $khg = Mill::where('code', 'KHG')->first();

        // PENTING: tukar password ini selepas first login di server live!
        User::firstOrCreate(
            ['email' => 'admin@ppnj.gov.my'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('Admin@123'),
                'role_id' => $adminRole->id,
                'mill_id' => null,
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'pengurusan@ppnj.gov.my'],
            [
                'name' => 'Pihak Pengurusan',
                'password' => Hash::make('Pengurusan@123'),
                'role_id' => $pengurusanRole->id,
                'mill_id' => null,
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'kilang.bbj@ppnj.gov.my'],
            [
                'name' => 'Pegawai Kilang Bukit Bujang',
                'password' => Hash::make('Kilang@123'),
                'role_id' => $pegawaiRole->id,
                'mill_id' => $bbj->id,
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'kilang.khg@ppnj.gov.my'],
            [
                'name' => 'Pegawai Kilang Kahang',
                'password' => Hash::make('Kilang@123'),
                'role_id' => $pegawaiRole->id,
                'mill_id' => $khg->id,
                'is_active' => true,
            ]
        );
    }
}
