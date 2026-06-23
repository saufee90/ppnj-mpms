<?php

namespace App\Http\Controllers;

use App\Models\DailyOperation;
use App\Models\KpiTarget;
use App\Models\Mill;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $mills = Mill::where('is_active', true)->get();

        $selectedMillId = $request->input('mill_id', $user->isPegawaiKilang() ? $user->mill_id : null);

        $today = now()->toDateString();

        $baseQuery = DailyOperation::query();
        if ($selectedMillId) {
            $baseQuery->where('mill_id', $selectedMillId);
        }
        if ($user->isPegawaiKilang()) {
            $baseQuery->where('mill_id', $user->mill_id);
        }

        $todayData = (clone $baseQuery)->where('tarikh', $today)->get();
        $summary = [
            'bts_diterima' => $todayData->sum('bts_diterima'),
            'bts_diproses' => $todayData->sum('bts_diproses'),
            'cpo' => $todayData->sum('pengeluaran_cpo'),
            'pk' => $todayData->sum('pengeluaran_pk'),
            'oer' => $todayData->avg('oer') ?? 0,
            'ker' => $todayData->avg('ker') ?? 0,
            'downtime' => $todayData->sum('downtime_jam'),
        ];

        $millsBelumHantar = collect();
        foreach ($mills as $mill) {
            $exists = DailyOperation::where('mill_id', $mill->id)->where('tarikh', $today)->exists();
            if (! $exists) {
                $millsBelumHantar->push($mill->name);
            }
        }

        $trendStart = now()->subDays(13)->toDateString();
        $trendData = (clone $baseQuery)
            ->where('tarikh', '>=', $trendStart)
            ->orderBy('tarikh')
            ->get()
            ->groupBy(fn ($row) => $row->tarikh->toDateString());

        $labels = [];
        $btsProcessedTrend = [];
        $cpoTrend = [];
        $oerTrend = [];
        $kerTrend = [];

        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $labels[] = now()->subDays($i)->format('d/m');
            $rows = $trendData->get($date, collect());
            $btsProcessedTrend[] = round($rows->sum('bts_diproses'), 2);
            $cpoTrend[] = round($rows->sum('pengeluaran_cpo'), 2);
            $oerTrend[] = round($rows->avg('oer') ?? 0, 2);
            $kerTrend[] = round($rows->avg('ker') ?? 0, 2);
        }

        $comparisonLabels = $mills->pluck('name');
        $comparisonBts = $mills->map(function ($mill) use ($today) {
            return DailyOperation::where('mill_id', $mill->id)->where('tarikh', $today)->sum('bts_diproses');
        });
        $comparisonDowntime = $mills->map(function ($mill) use ($today) {
            return DailyOperation::where('mill_id', $mill->id)->where('tarikh', $today)->sum('downtime_jam');
        });

        $target = KpiTarget::getActiveTarget($selectedMillId, now()->year);

        $mtd = (clone $baseQuery)->forMonth(now()->year, now()->month)->get();
        $ytd = (clone $baseQuery)->forYear(now()->year)->get();

        return view('dashboard.index', [
            'mills' => $mills,
            'selectedMillId' => $selectedMillId,
            'summary' => $summary,
            'millsBelumHantar' => $millsBelumHantar,
            'labels' => $labels,
            'btsProcessedTrend' => $btsProcessedTrend,
            'cpoTrend' => $cpoTrend,
            'oerTrend' => $oerTrend,
            'kerTrend' => $kerTrend,
            'comparisonLabels' => $comparisonLabels,
            'comparisonBts' => $comparisonBts,
            'comparisonDowntime' => $comparisonDowntime,
            'target' => $target,
            'mtdCpo' => $mtd->sum('pengeluaran_cpo'),
            'mtdBts' => $mtd->sum('bts_diproses'),
            'ytdCpo' => $ytd->sum('pengeluaran_cpo'),
            'ytdBts' => $ytd->sum('bts_diproses'),
        ]);
    }
}
