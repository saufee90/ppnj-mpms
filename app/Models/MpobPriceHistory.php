<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpobPriceHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'trade_date',
        'price',
        'source_checked_at',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'price' => 'decimal:2',
        'source_checked_at' => 'datetime',
    ];
}
