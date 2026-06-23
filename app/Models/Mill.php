<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mill extends Model
{
    protected $fillable = ['name', 'code', 'location', 'is_active'];

    public function dailyOperations(): HasMany
    {
        return $this->hasMany(DailyOperation::class);
    }

    public function kpiTargets(): HasMany
    {
        return $this->hasMany(KpiTarget::class);
    }

    public function downtimeLogs(): HasMany
    {
        return $this->hasMany(DowntimeLog::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
