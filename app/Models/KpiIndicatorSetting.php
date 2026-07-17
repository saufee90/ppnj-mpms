<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiIndicatorSetting extends Model
{
    protected $fillable = [
        'mill_id',
        'year',
        'indicator_code',
        'indicator_name',
        'unit',
        'evaluation_direction',
        'green_threshold',
        'red_threshold',
        'period_target',
        'monthly_targets',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'monthly_targets' => 'array',
            'is_active' => 'boolean',
            'year' => 'integer',
            'green_threshold' => 'float',
            'red_threshold' => 'float',
            'period_target' => 'float',
        ];
    }

    public function mill(): BelongsTo
    {
        return $this->belongsTo(Mill::class);
    }

    public function scopeForScope(Builder $query, ?int $millId): Builder
    {
        if ($millId === null) {
            return $query->whereNull('mill_id');
        }

        return $query->where('mill_id', $millId);
    }
}
