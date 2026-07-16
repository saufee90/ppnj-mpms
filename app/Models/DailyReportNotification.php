<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyReportNotification extends Model
{
    protected $fillable = [
        'report_date',
        'channel',
        'recipient',
        'status',
        'sent_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }
}