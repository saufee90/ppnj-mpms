<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ppk extends Model
{
    protected $fillable = ['code', 'name', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function weighbridgeCentres(): HasMany
    {
        return $this->hasMany(WeighbridgeCentre::class);
    }
}
