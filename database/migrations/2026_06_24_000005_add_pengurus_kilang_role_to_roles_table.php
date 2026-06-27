<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $exists = DB::table('roles')->where('name', 'pengurus_kilang')->exists();

        if (! $exists) {
            DB::table('roles')->insert([
                'name' => 'pengurus_kilang',
                'label' => 'Pengurus Kilang',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('roles')->where('name', 'pengurus_kilang')->delete();
    }
};
