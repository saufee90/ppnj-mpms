<?php

namespace App\Http\Controllers;

use App\Models\DailyOperation;
use App\Models\Mill;
use Illuminate\Http\Request;

class PerformanceAnalysisController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $mills = Mill::where('is_active', true)->get();

        $millId = $request->input('mill_id', $user->isMillScopedRole() ? $user->mill_id : null);
        $year = $request->input('tahun', now()->year);

        $query = DailyOperation::forYear($year);
        if ($millId) {
            $query->where('mill_id', $millId);
        }
        if ($user->isMillScopedRole()) {
            $query->where('mill_id', $user->mill_id);
        }

        $monthlyStats = collect(range(1, 12))->map(function ($month) use ($millId, $year, $user) {
            $q = DailyOperation::forMonth($year, $month);
            if ($millId) $q->where('mill_id', $millId);
            if ($user->isMillScopedRole()) $q->where('mill_id', $user->mill_id);
            $rows = $q->get();
            $ratioRows = $rows->filter(fn ($row) => (float) $row->bts_diproses > 0);
            $sumBtsDiprosesForRatio = (float) $ratioRows->sum('bts_diproses');
            $oer = $sumBtsDiprosesForRatio > 0
                ? (((float) $ratioRows->sum('produksi_cpo')) / $sumBtsDiprosesForRatio) * 100
                : 0;
            $ker = $sumBtsDiprosesForRatio > 0
                ? (((float) $ratioRows->sum('produksi_pk')) / $sumBtsDiprosesForRatio) * 100
                : 0;

            return [
                'bulan' => \Carbon\Carbon::create()->month($month)->translatedFormat('M'),
                'bts_diproses' => round($rows->sum('bts_diproses'), 2),
                'cpo' => round($rows->sum('pengeluaran_cpo'), 2),
                'pk' => round($rows->sum('pengeluaran_pk'), 2),
                'oer' => round($oer, 2),
                'ker' => round($ker, 2),
                'downtime' => round($rows->sum('downtime_jam'), 2),
                'utilisation' => round($rows->avg('utilisation_rate') ?? 0, 2),
            ];
        });

        return view('analisis.index', compact('mills', 'millId', 'year', 'monthlyStats'));
    }
}
