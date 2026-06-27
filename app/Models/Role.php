<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'label'];

    const ADMIN = 'admin';
    const PEGAWAI_KILANG = 'pegawai_kilang';
    const PENGURUS_KILANG = 'pengurus_kilang';
    const PENGURUSAN = 'pengurusan';

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
