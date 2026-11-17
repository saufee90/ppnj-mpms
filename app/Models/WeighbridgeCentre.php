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

    public function ffbGroupingReviewReason(): ?string
    {
        if (($this->source_category === 'ppnj_ppk_integrated' || $this->ppk_id !== null)
            && ($this->ppk_id === null || ! $this->ppk || ! $this->ppk->isValidFfbGrouping())) {
            return 'missing_or_inactive_ppk';
        }

        return null;
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

    public function ffbWeighbridgeTickets(): HasMany
    {
        return $this->hasMany(FfbWeighbridgeTicket::class);
    }
}
