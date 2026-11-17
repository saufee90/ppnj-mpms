<?php

namespace App\Http\Controllers;

use App\Models\DailyOperation;
use App\Models\Mill;
use App\Services\YtdPerformanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PerformanceAnalysisController extends Controller
{
    public function index(Request $request, YtdPerformanceService $ytdPerformanceService)
    {
        $user = $request->user();
        $mills = Mill::where('is_active', true)->get();

        $millId = $user->isMillScopedRole()
            ? $user->mill_id
            : $request->input('mill_id');
        $year = (int) $request->input('tahun', now()->year);
        $selectedMillIds = $millId
            ? [(int) $millId]
            : $mills->pluck('id')->all();
        $analysisRows = null;

        if ($year === 2026 && $selectedMillIds !== []) {
            $cutoff = $year === (int) now()->year
                ? Carbon::yesterday()
                : Carbon::create($year, 12, 31);
            $analysisRows = $ytdPerformanceService->rows($cutoff, $selectedMillIds);
        }

        $monthlyStats = collect(range(1, 12))->map(function ($month) use (
            $millId,
            $year,
            $user,
            $analysisRows,
            $mills
        ) {
            if ($analysisRows !== null) {
                $rows = $analysisRows->filter(
                    fn ($row) => (int) $row->tarikh->month === $month
                        && (! $millId || (int) $row->mill_id === (int) $millId)
                );
            } else {
                $query = DailyOperation::forMonth($year, $month);
                if ($millId) {
                    $query->where('mill_id', $millId);
                }
                if ($user->isMillScopedRole()) {
                    $query->where('mill_id', $user->mill_id);
                }
                $rows = $query->get();
            }

            if ($rows->isEmpty()) {
                return [
                    'bulan' => Carbon::create()->month($month)->translatedFormat('M'),
                    'bts_diproses' => null,
                    'cpo' => null,
                    'pk' => null,
                    'oer' => null,
                    'ker' => null,
                    'downtime' => null,
                    'throughput' => null,
                    'utilisation' => null,
                ];
            }

            $btsDiproses = (float) $rows->sum('bts_diproses');
            $operatingHours = (float) $rows->sum('jam_operasi');
            $throughput = $operatingHours > 0 ? $btsDiproses / $operatingHours : null;
            $capacityHours = 0.0;
            foreach ($mills->whereIn('id', $rows->pluck('mill_id')->unique()) as $mill) {
                $capacity = match ($mill->code) {
                    'BBJ' => 30.0,
                    'KHG' => 60.0,
                    default => 0.0,
                };
                $capacityHours += (float) $rows->where('mill_id', $mill->id)->sum('jam_operasi') * $capacity;
            }

            return [
                'bulan' => Carbon::create()->month($month)->translatedFormat('M'),
                'bts_diproses' => round($btsDiproses, 2),
                'cpo' => round((float) $rows->sum('pengeluaran_cpo'), 2),
                'pk' => round((float) $rows->sum('pengeluaran_pk'), 2),
                'oer' => $btsDiproses > 0 ? round((float) $rows->sum('produksi_cpo') / $btsDiproses * 100, 2) : null,
                'ker' => $btsDiproses > 0 ? round((float) $rows->sum('produksi_pk') / $btsDiproses * 100, 2) : null,
                'downtime' => $year === 2026 && $month <= 6
                    ? null
                    : round((float) $rows->sum('downtime_jam'), 2),
                'throughput' => $throughput === null ? null : round($throughput, 2),
                'utilisation' => $capacityHours > 0 ? round($btsDiproses / $capacityHours * 100, 2) : null,
            ];
        });

        return view('analisis.index', compact('mills', 'millId', 'year', 'monthlyStats'));
    }
}
