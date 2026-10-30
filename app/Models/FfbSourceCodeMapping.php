<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FfbSourceCodeMapping extends Model
{
    protected $fillable = ['ffb_source_group_id', 'code', 'weighbridge_centre_id', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'ffb_source_group_id' => 'integer',
            'weighbridge_centre_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (FfbSourceCodeMapping $mapping) {
            $mapping->normalized_code = FfbSourceAlias::normalizeCode($mapping->code);
        });
    }

    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => [
                'code' => $value,
                'normalized_code' => FfbSourceAlias::normalizeCode($value),
            ],
        );
    }

    public function sourceGroup(): BelongsTo
    {
        return $this->belongsTo(FfbSourceGroup::class, 'ffb_source_group_id');
    }

    public function weighbridgeCentre(): BelongsTo
    {
        return $this->belongsTo(WeighbridgeCentre::class);
    }
}
