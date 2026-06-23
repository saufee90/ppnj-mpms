<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    protected $fillable = [
        'jenis', 'format', 'mill_id', 'tarikh_mula', 'tarikh_akhir',
        'generated_by', 'file_path',
    ];

    protected function casts(): array
    {
        return [
            'tarikh_mula' => 'date',
            'tarikh_akhir' => 'date',
        ];
    }

    public function mill(): BelongsTo
    {
        return $this->belongsTo(Mill::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
