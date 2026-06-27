<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'admin', 'label' => 'Admin'],
            ['name' => 'pegawai_kilang', 'label' => 'Pegawai Kilang'],
            ['name' => 'pengurus_kilang', 'label' => 'Pengurus Kilang'],
            ['name' => 'pengurusan', 'label' => 'Pengurusan'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name']], $role);
        }
    }
}
