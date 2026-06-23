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
     * NOTA PENTING: OER, KER, Throughput dan Utilisation TIDAK dikira automatik.
     * Nilai sebenar datang dari sistem lab kualiti / operasi yang berasingan,
     * dan di-key-in oleh Pegawai Kilang pada keesokan harinya (T+1) melalui
     * menu "Kemaskini Kualiti". Semua field ini boleh null sehingga dikemaskini.
     */
    public function calculateKpi(): void
    {
        // Tiada auto-calculation. Disimpan sebagai placeholder method
        // sekiranya formula automatik diperlukan semula pada masa hadapan.
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
