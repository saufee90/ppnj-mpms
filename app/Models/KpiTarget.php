<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiTarget extends Model
{
    protected $fillable = [
        'mill_id', 'oer_target', 'ker_target', 'ffa_max', 'downtime_max_hours',
        'effective_year', 'is_active',
    ];

    public function mill(): BelongsTo
    {
        return $this->belongsTo(Mill::class);
    }

    /**
     * Dapatkan sasaran KPI yang berkuatkuasa untuk kilang + tahun tertentu.
     * Jika tiada sasaran spesifik kilang, guna sasaran global (mill_id = null).
     */
    public static function getActiveTarget(?int $millId, int $year): self
    {
        $target = static::where('effective_year', $year)
            ->where('is_active', true)
            ->where(function ($q) use ($millId) {
                $q->where('mill_id', $millId)->orWhereNull('mill_id');
            })
            ->orderByRaw('mill_id IS NULL') // keutamaan kilang spesifik dulu
            ->first();

        return $target ?? new self([
            'oer_target' => 20.00,
            'ker_target' => 5.00,
            'ffa_max' => 5.00,
            'downtime_max_hours' => 2.00,
        ]);
    }
}
