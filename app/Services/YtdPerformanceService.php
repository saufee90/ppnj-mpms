<?php

namespace App\Services;

use App\Models\DailyOperation;
use App\Models\Mill;
use App\Models\MillMonthlyHistory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RuntimeException;

class YtdPerformanceService
{
    /**
     * Historical projections use daily field names only in memory. They are never DailyOperation models.
     * Jan-Jun 2026 cutoffs include completed months only; partial months are not prorated.
     * Daily rows start on July 1 in 2026, otherwise January 1, and never extend past the cutoff.
     */
    public function rows(CarbonInterface $asOf, array $millIds): Collection
    {
        $cutoff = CarbonImmutable::instance($asOf)->startOfDay();
        $start = $cutoff->year === 2026 ? '2026-07-01' : $cutoff->startOfYear()->toDateString();
        $daily = DailyOperation::query()->whereIn('mill_id', $millIds)
            ->whereBetween('tarikh', [$start, $cutoff->toDateString()])->get();

        if ($cutoff->year !== 2026) {
            return $daily->toBase();
        }

        $completedMonth = $this->completedHistoricalMonth($cutoff);
        $historicalMills = Mill::query()->whereIn('id', $millIds)->whereIn('code', ['KHG', 'BBJ'])->pluck('id');
        $history = MillMonthlyHistory::query()->whereIn('mill_id', $historicalMills)
            ->where('year', 2026)->whereBetween('month', [1, $completedMonth])->get();
        if ($history->count() !== $historicalMills->count() * $completedMonth) {
            throw new RuntimeException('Jan-Jun 2026 historical performance is incomplete. Run the controlled historical import before reporting YTD.');
        }

        $projections = $history->map(fn (MillMonthlyHistory $row) => (object) [
            'mill_id' => $row->mill_id,
            'tarikh' => CarbonImmutable::create(2026, $row->month, 1)->endOfMonth()->startOfDay(),
            'bts_diterima' => $row->bts_diterima,
            'bts_diproses' => $row->bts_diproses,
            'jam_operasi' => $row->jam_operasi,
            'produksi_cpo' => $row->pengeluaran_cpo,
            'pengeluaran_cpo' => $row->jualan_cpo,
            'produksi_pk' => $row->pengeluaran_pk,
            'pengeluaran_pk' => $row->jualan_pk,
        ]);

        return $daily->toBase()->concat($projections)->values();
    }

    public function metrics(Collection $rows): array
    {
        $processed = (float) $rows->sum('bts_diproses');
        $hours = (float) $rows->sum('jam_operasi');
        $capacityHours = 0.0;
        $mills = Mill::query()->whereIn('id', $rows->pluck('mill_id')->unique())->get();
        foreach ($mills as $mill) {
            $capacity = match ($mill->code) {
                'KHG' => 60.0,
                'BBJ' => 30.0,
                default => 0.0,
            };
            $capacityHours += (float) $rows->where('mill_id', $mill->id)->sum('jam_operasi') * $capacity;
        }

        return [
            'bts_diterima' => $rows->isNotEmpty() ? round((float) $rows->sum('bts_diterima'), 2) : null,
            'bts_diproses' => $rows->isNotEmpty() ? round($processed, 2) : null,
            'pengeluaran_cpo' => $rows->isNotEmpty() ? round((float) $rows->sum('produksi_cpo'), 2) : null,
            'jualan_cpo' => $rows->isNotEmpty() ? round((float) $rows->sum('pengeluaran_cpo'), 2) : null,
            'pengeluaran_pk' => $rows->isNotEmpty() ? round((float) $rows->sum('produksi_pk'), 2) : null,
            'jualan_pk' => $rows->isNotEmpty() ? round((float) $rows->sum('pengeluaran_pk'), 2) : null,
            'jam_operasi' => round($hours, 2),
            'throughput' => $hours > 0 ? round($processed / $hours, 2) : null,
            'oer' => $processed > 0 ? round((float) $rows->sum('produksi_cpo') / $processed * 100, 2) : null,
            'ker' => $processed > 0 ? round((float) $rows->sum('produksi_pk') / $processed * 100, 2) : null,
            'utilisation' => $capacityHours > 0 ? round($processed / $capacityHours * 100, 2) : null,
        ];
    }

    public function note(CarbonInterface $asOf): ?string
    {
        if ($asOf->year !== 2026) {
            return null;
        }
        $cutoff = CarbonImmutable::instance($asOf);
        if ($cutoff->month <= 6 && ! $cutoff->isLastOfMonth()) {
            $through = $this->completedHistoricalMonth($cutoff);

            return $through === 0
                ? 'YTD belum tersedia: data sejarah hanya bulanan penuh; Januari belum lengkap pada tarikh pilihan.'
                : 'YTD sejarah sehingga '.$cutoff->startOfMonth()->subDay()->format('d/m/Y')
                    .'; bulan pilihan tidak disertakan kerana data sejarah hanya bulanan penuh.';
        }

        return 'YTD 2026 menggabungkan sejarah bulanan Januari-Jun dan rekod harian mulai 01 Julai sehingga tarikh pilihan.';
    }

    private function completedHistoricalMonth(CarbonImmutable $cutoff): int
    {
        return min(6, $cutoff->month - ($cutoff->isLastOfMonth() ? 0 : 1));
    }
}
