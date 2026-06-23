<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DowntimeLog extends Model
{
    protected $fillable = [
        'daily_operation_id', 'mill_id', 'tarikh', 'masa_mula', 'masa_tamat',
        'tempoh_jam', 'kategori', 'sebab', 'tindakan',
    ];

    protected function casts(): array
    {
        return [
            'tarikh' => 'date',
        ];
    }

    public function dailyOperation(): BelongsTo
    {
        return $this->belongsTo(DailyOperation::class);
    }

    public function mill(): BelongsTo
    {
        return $this->belongsTo(Mill::class);
    }
}
