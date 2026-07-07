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
            $operatingRows = $rows->filter(function ($row) {
                return (float) $row->bts_diproses > 0 && (float) $row->jam_operasi > 0;
            });

            $sumOperatingBtsDiproses = (float) $operatingRows->sum('bts_diproses');
            $sumOperatingJam = (float) $operatingRows->sum('jam_operasi');

            $oer = $this->computeRate((float) $operatingRows->sum('produksi_cpo'), $sumOperatingBtsDiproses);
            $ker = $this->computeRate((float) $operatingRows->sum('produksi_pk'), $sumOperatingBtsDiproses);
            $throughput = $sumOperatingJam > 0 ? round($sumOperatingBtsDiproses / $sumOperatingJam, 2) : 0;
            $utilisation = $this->computeUtilisation($throughput, $mill->code);

            return [
                'mill' => $mill->name,
                'bts_diproses' => round($rows->sum('bts_diproses'), 2),
                'cpo' => round($rows->sum('pengeluaran_cpo'), 2),
                'pk' => round($rows->sum('pengeluaran_pk'), 2),
                'oer' => round($oer, 2),
                'ker' => round($ker, 2),
                'downtime' => round($rows->sum('downtime_jam'), 2),
                'ffa' => round($operatingRows->avg('ffa') ?? 0, 2),
                'moisture' => round($operatingRows->avg('moisture') ?? 0, 2),
                'dirt' => round($operatingRows->avg('dirt') ?? 0, 2),
                'throughput' => round($throughput, 2),
                'utilisation' => round($utilisation, 2),
            ];
        });

        return view('perbandingan.index', compact('mills', 'year', 'month', 'comparison'));
    }

    private function computeRate(float|int $production, float|int $btsDiproses): float
    {
        $btsDiproses = (float) $btsDiproses;
        if ($btsDiproses <= 0) {
            return 0.0;
        }

        return (((float) $production / $btsDiproses) * 100);
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

        return ($throughput / $capacity) * 100;
    }
}
