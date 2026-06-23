<?php

namespace App\Http\Controllers;

use App\Models\DailyOperation;
use App\Models\Mill;
use Illuminate\Http\Request;

class MillComparisonController extends Controller
{
    public function index(Request $request)
    {
        $mills = Mill::where('is_active', true)->get();
        $year = $request->input('tahun', now()->year);
        $month = $request->input('bulan');

        $comparison = $mills->map(function ($mill) use ($year, $month) {
            $q = DailyOperation::where('mill_id', $mill->id)->forYear($year);
            if ($month) {
                $q->whereMonth('tarikh', $month);
            }
            $rows = $q->get();

            return [
                'mill' => $mill->name,
                'bts_diproses' => round($rows->sum('bts_diproses'), 2),
                'cpo' => round($rows->sum('pengeluaran_cpo'), 2),
                'pk' => round($rows->sum('pengeluaran_pk'), 2),
                'oer' => round($rows->avg('oer') ?? 0, 2),
                'ker' => round($rows->avg('ker') ?? 0, 2),
                'downtime' => round($rows->sum('downtime_jam'), 2),
                'ffa' => round($rows->avg('ffa') ?? 0, 2),
                'utilisation' => round($rows->avg('utilisation_rate') ?? 0, 2),
            ];
        });

        return view('perbandingan.index', compact('mills', 'year', 'month', 'comparison'));
    }
}
