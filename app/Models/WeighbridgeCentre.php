<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeighbridgeCentre extends Model
{
    public const SOURCE_CATEGORIES = [
        'ppnj_owned',
        'ppnj_ppk_integrated',
        'external_supplier',
    ];

    protected $fillable = ['ppk_id', 'code', 'name', 'source_category', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'ppk_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function ppk(): BelongsTo
    {
        return $this->belongsTo(Ppk::class);
    }

    public function sourceAliases(): HasMany
    {
        return $this->hasMany(FfbSourceAlias::class);
    }

    public function sourceGroups(): HasMany
    {
        return $this->hasMany(FfbSourceGroup::class);
    }

    public function sourceCodeMappings(): HasMany
    {
        return $this->hasMany(FfbSourceCodeMapping::class);
    }
}
