<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ppk extends Model
{
    public const OFFICIAL_PPK = 'official_ppk';

    public const INDEPENDENT_INTEGRATED_ESTATE = 'independent_integrated_estate';

    public const KLUANG_UTARA_CODE = 'ESTATE-USAHASAMA-KLUANG-UTARA';

    public const KLUANG_UTARA_NAME = 'Ladang Usahasama PPK Kluang Utara';

    protected $fillable = ['code', 'name', 'entity_type', 'is_active'];

    protected $attributes = ['is_active' => true, 'entity_type' => self::OFFICIAL_PPK];

    // Organizational PPK lists must exclude independent FFB reporting parents.
    public function scopeOfficial(Builder $query): Builder
    {
        return $query->where('entity_type', self::OFFICIAL_PPK);
    }

    public function isValidFfbGrouping(): bool
    {
        return $this->is_active && in_array($this->entity_type, [
            self::OFFICIAL_PPK, self::INDEPENDENT_INTEGRATED_ESTATE,
        ], true);
    }

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

    public function ffbWeighbridgeTickets(): HasMany
    {
        return $this->hasMany(FfbWeighbridgeTicket::class);
    }
}
