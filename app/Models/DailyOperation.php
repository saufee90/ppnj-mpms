<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyOperation extends Model
{
    protected $fillable = [
        'tarikh', 'mill_id', 'shift', 'officer_id',
        'operation_status',
        'bts_diterima', 'bts_diproses', 'baki_bts_semalam', 'baki_bts_selepas_diproses', 'jam_operasi', 'downtime_jam', 'sebab_downtime',
        'pengeluaran_cpo', 'pengeluaran_pk', 'pk_kcp_to_hopper', 'produksi_cpo', 'produksi_pk', 'stok_cpo', 'stok_pk', 'stok_cpo_yesterday', 'stok_pk_yesterday',
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
     * Kira secara automatik OER dan KER berdasarkan nilai produksi dan BTS diproses.
     * OER = (produksi_cpo / bts_diproses) * 100
     * KER = (produksi_pk / bts_diproses) * 100
     */
    public function calculateKpi(): void
    {
        $btsDiproses = $this->bts_diproses ?? 0;
        $produksiCpo = $this->produksi_cpo ?? 0;
        $produksiPk = $this->produksi_pk ?? 0;

        if ($btsDiproses > 0) {
            $this->oer = round(($produksiCpo / $btsDiproses) * 100, 2);
            $this->ker = round(($produksiPk / $btsDiproses) * 100, 2);
        } else {
            $this->oer = 0;
            $this->ker = 0;
        }

        if ($this->throughput !== null && $this->throughput >= 0) {
            $capacity = $this->getMillCapacity();
            if ($capacity > 0) {
                $this->utilisation_rate = round(($this->throughput / $capacity) * 100, 2);
            } else {
                $this->utilisation_rate = 0;
            }
        }
    }

    public function getMillCapacity(): float
    {
        $code = $this->mill?->code ?? null;

        return match ($code) {
            'KHG' => 60.0,
            'BBJ' => 30.0,
            default => 0.0,
        };
    }

    public function computeThroughput(): float
    {
        if (isset($this->operation_status) && $this->operation_status !== 'Operasi') {
            return 0.0;
        }

        if (! isset($this->bts_diproses) || ! isset($this->jam_operasi) || $this->jam_operasi <= 0) {
            return 0.0;
        }

        return round($this->bts_diproses / $this->jam_operasi, 2);
    }

    public function computeUtilisationRate(float $throughput): float
    {
        $capacity = $this->getMillCapacity();

        if ($capacity <= 0 || $throughput <= 0) {
            return 0.0;
        }

        return round(($throughput / $capacity) * 100, 2);
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

    public function scopeOperated($query)
    {
        return $query->where(function ($query) {
            $query->where('operation_status', 'Operasi')
                ->orWhere(function ($legacyQuery) {
                    $legacyQuery->whereNull('operation_status')
                        ->where(function ($fallbackQuery) {
                            $fallbackQuery->where('bts_diproses', '>', 0)
                                ->orWhere('jam_operasi', '>', 0);
                        });
                });
        });
    }
}
