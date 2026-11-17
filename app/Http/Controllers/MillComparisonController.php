<?php

namespace App\Http\Controllers;

use App\Models\DailyOperation;
use App\Models\Mill;
use App\Models\MillMonthlyHistory;
use App\Services\YtdPerformanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MillComparisonController extends Controller
{
    public function index(Request $request, YtdPerformanceService $ytdPerformanceService)
    {
        $mills = Mill::where('is_active', true)->get();
        $year = (int) $request->input('tahun', now()->year);
        $month = $request->filled('bulan') ? (int) $request->input('bulan') : null;

        $yearEnd = Carbon::create($year, 12, 31);
        $ytdCutoff = $year === now()->year ? Carbon::yesterday() : $yearEnd;
        $ytdRows = $year === 2026 && ! $month
            ? $ytdPerformanceService->rows($ytdCutoff, $mills->pluck('id')->all())
            : null;
        $history = $year === 2026 && (! $month || $month <= 6)
            ? MillMonthlyHistory::query()
                ->whereIn('mill_id', $mills->pluck('id'))
                ->where('year', 2026)
                ->when($month, fn ($query) => $query->where('month', $month), fn ($query) => $query->whereBetween('month', [1, 6]))
                ->get()
                ->groupBy('mill_id')
            : collect();

        $comparison = $mills->map(function ($mill) use ($year, $month, $ytdRows, $ytdPerformanceService, $ytdCutoff, $history) {
            if ($ytdRows !== null) {
                $rows = $ytdRows->where('mill_id', $mill->id);
                $metrics = $ytdPerformanceService->metrics($rows);

                return [
                    'mill' => $mill->name,
                    'bts_diproses' => $metrics['bts_diproses'],
                    'cpo' => $metrics['jualan_cpo'],
                    'pk' => $metrics['jualan_pk'],
                    'oer' => $metrics['oer'],
                    'ker' => $metrics['ker'],
                    'downtime' => null,
                    'ffa' => null,
                    'moisture' => null,
                    'dirt' => null,
                    'throughput' => $metrics['throughput'],
                    'utilisation' => $metrics['utilisation'],
                ];
            }

            if ($year === 2026 && $month !== null && $month <= 6) {
                $monthlyHistory = $history->get($mill->id, collect());

                return $this->historicalResult($mill->name, $monthlyHistory, $mill->code);
            }

            $query = DailyOperation::where('mill_id', $mill->id)->forYear($year);
            if ($month !== null) {
                $query->whereMonth('tarikh', $month);
            } else {
                if ($year === 2026) {
                    $query->whereDate('tarikh', '>=', '2026-07-01');
                }
                $query->whereDate('tarikh', '<=', $ytdCutoff->toDateString());
            }

            return $this->dailyResult($mill->name, $query->get(), $mill->code);
        });

        return view('perbandingan.index', compact('mills', 'year', 'month', 'comparison'));
    }

    private function historicalResult(string $millName, $historyRows, string $millCode): array
    {
        if ($historyRows->isEmpty()) {
            return $this->emptyResult($millName);
        }

        $processed = (float) $historyRows->sum('bts_diproses');
        $hours = (float) $historyRows->sum('jam_operasi');
        $producedCpo = (float) $historyRows->sum('pengeluaran_cpo');
        $producedPk = (float) $historyRows->sum('pengeluaran_pk');
        $throughput = $hours > 0 ? $processed / $hours : null;

        return [
            'mill' => $millName,
            'bts_diproses' => round($processed, 2),
            'cpo' => round((float) $historyRows->sum('jualan_cpo'), 2),
            'pk' => round((float) $historyRows->sum('jualan_pk'), 2),
            'oer' => $processed > 0 ? round($producedCpo / $processed * 100, 2) : null,
            'ker' => $processed > 0 ? round($producedPk / $processed * 100, 2) : null,
            'downtime' => null,
            'ffa' => null,
            'moisture' => null,
            'dirt' => null,
            'throughput' => $throughput === null ? null : round($throughput, 2),
            'utilisation' => $throughput === null ? null : $this->computeUtilisation($throughput, $millCode),
        ];
    }

    private function dailyResult(string $millName, $rows, string $millCode): array
    {
        if ($rows->isEmpty()) {
            return $this->emptyResult($millName);
        }

        $processed = (float) $rows->sum('bts_diproses');
        $hours = (float) $rows->sum('jam_operasi');
        $throughput = $hours > 0 ? $processed / $hours : null;
        $operatingRows = $rows->filter(fn ($row) => (float) $row->bts_diproses > 0 && (float) $row->jam_operasi > 0);

        return [
            'mill' => $millName,
            'bts_diproses' => round($processed, 2),
            'cpo' => round((float) $rows->sum('pengeluaran_cpo'), 2),
            'pk' => round((float) $rows->sum('pengeluaran_pk'), 2),
            'oer' => $processed > 0 ? round((float) $rows->sum('produksi_cpo') / $processed * 100, 2) : null,
            'ker' => $processed > 0 ? round((float) $rows->sum('produksi_pk') / $processed * 100, 2) : null,
            'downtime' => round((float) $rows->sum('downtime_jam'), 2),
            'ffa' => $operatingRows->isNotEmpty() ? round((float) $operatingRows->avg('ffa'), 2) : null,
            'moisture' => $operatingRows->isNotEmpty() ? round((float) $operatingRows->avg('moisture'), 2) : null,
            'dirt' => $operatingRows->isNotEmpty() ? round((float) $operatingRows->avg('dirt'), 2) : null,
            'throughput' => $throughput === null ? null : round($throughput, 2),
            'utilisation' => $throughput === null ? null : $this->computeUtilisation($throughput, $millCode),
        ];
    }

    private function emptyResult(string $millName): array
    {
        return [
            'mill' => $millName,
            'bts_diproses' => null,
            'cpo' => null,
            'pk' => null,
            'oer' => null,
            'ker' => null,
            'downtime' => null,
            'ffa' => null,
            'moisture' => null,
            'dirt' => null,
            'throughput' => null,
            'utilisation' => null,
        ];
    }

    private function computeUtilisation(float $throughput, ?string $millCode): float
    {
        $capacity = match ($millCode) {
            'KHG' => 60.0,
            'BBJ' => 30.0,
            default => 0.0,
        };

        if ($capacity <= 0 || $throughput <= 0) {
            return 0.0;
        }

        return round(($throughput / $capacity) * 100, 2);
    }
}
