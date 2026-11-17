<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A null centre requires contextual code resolution; a linked centre owns its ticket/block codes.
 */
class FfbSourceGroup extends Model
{
    protected $fillable = ['heading', 'source_category', 'weighbridge_centre_id', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'weighbridge_centre_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (FfbSourceGroup $group) {
            $group->normalized_heading = FfbSourceAlias::normalizeCode($group->heading);
        });
    }

    protected function heading(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => [
                'heading' => $value,
                'normalized_heading' => FfbSourceAlias::normalizeCode($value),
            ],
        );
    }

    public function weighbridgeCentre(): BelongsTo
    {
        return $this->belongsTo(WeighbridgeCentre::class);
    }

    public function codeMappings(): HasMany
    {
        return $this->hasMany(FfbSourceCodeMapping::class);
    }

    public function ffbWeighbridgeTickets(): HasMany
    {
        return $this->hasMany(FfbWeighbridgeTicket::class);
    }
}
