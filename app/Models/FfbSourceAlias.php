<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FfbSourceAlias extends Model
{
    protected $fillable = ['weighbridge_centre_id', 'alias_code', 'is_active'];

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
        static::saving(function (FfbSourceAlias $alias) {
            $alias->normalized_code = self::normalizeCode($alias->alias_code);
        });
    }

    protected function aliasCode(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => [
                'alias_code' => $value,
                'normalized_code' => self::normalizeCode($value),
            ],
        );
    }

    /**
     * Trim surrounding ASCII whitespace and uppercase; retain punctuation and internal spacing.
     */
    public static function normalizeCode(string $code): string
    {
        $normalized = Str::upper(trim($code));

        if ($normalized === '') {
            throw new InvalidArgumentException('FFB source alias code must not be blank.');
        }

        return $normalized;
    }

    public function weighbridgeCentre(): BelongsTo
    {
        return $this->belongsTo(WeighbridgeCentre::class);
    }
}
