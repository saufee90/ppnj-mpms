<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MillMonthlyHistory extends Model
{
    public const FIELDS = [
        'bts_diterima', 'bts_diproses', 'jam_operasi',
        'pengeluaran_cpo', 'jualan_cpo', 'pengeluaran_pk', 'jualan_pk',
    ];

    protected $fillable = [
        'mill_id', 'year', 'month', ...self::FIELDS,
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            ...array_fill_keys(self::FIELDS, 'decimal:2'),
        ];
    }

    public function mill(): BelongsTo
    {
        return $this->belongsTo(Mill::class);
    }
}
