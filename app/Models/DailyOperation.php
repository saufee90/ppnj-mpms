<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyOperation extends Model
{
    protected $fillable = [
        'tarikh', 'mill_id', 'shift', 'officer_id',
        'bts_diterima', 'bts_diproses', 'baki_stok_bts', 'jam_operasi', 'downtime_jam', 'sebab_downtime',
        'pengeluaran_cpo', 'pengeluaran_pk', 'stok_cpo', 'stok_pk',
        'oer', 'ker', 'ffa', 'moisture', 'dirt', 'throughput', 'utilisation_rate',
        'isu_operasi', 'tindakan_pembetulan', 'catatan_tambahan', 'status',
    ];

    protected function casts(): array
    {
        return [
            'tarikh' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DailyOperation $data) {
            $data->calculateKpi();
        });
    }

    /**
     * Kira formula automatik:
     * - OER (%) = CPO produced / BTS processed x 100
     * - KER (%) = PK produced / BTS processed x 100
     * - Throughput = BTS processed / jam operasi
     * - Utilisation (%) = jam operasi / 24 x 100
     */
    public function calculateKpi(): void
    {
        $btsProcessed = (float) $this->bts_diproses;
        $jamOperasi = (float) $this->jam_operasi;

        $this->oer = $btsProcessed > 0
            ? round(($this->pengeluaran_cpo / $btsProcessed) * 100, 2)
            : 0;

        $this->ker = $btsProcessed > 0
            ? round(($this->pengeluaran_pk / $btsProcessed) * 100, 2)
            : 0;

        $this->throughput = $jamOperasi > 0
            ? round($btsProcessed / $jamOperasi, 2)
            : 0;

        $this->utilisation_rate = round(($jamOperasi / 24) * 100, 2);
    }

    public function mill(): BelongsTo
    {
        return $this->belongsTo(Mill::class);
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    public function downtimeLogs(): HasMany
    {
        return $this->hasMany(DowntimeLog::class);
    }

    // Scopes untuk filter senang
    public function scopeForMill($query, $millId)
    {
        return $millId ? $query->where('mill_id', $millId) : $query;
    }

    public function scopeForMonth($query, $year, $month)
    {
        return $query->whereYear('tarikh', $year)->whereMonth('tarikh', $month);
    }

    public function scopeForYear($query, $year)
    {
        return $query->whereYear('tarikh', $year);
    }
}
