<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DailyOperation;
use App\Models\Mill;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $mills = Mill::where('is_active', true)->get();
        $query = $this->filteredQuery($request);
        $records = (clone $query)->with('mill')->orderBy('tarikh')->get();
        $reportPeriodTitle = $this->resolveReportPeriodTitle($request);
        $summaryBtsDiproses = (float) $records->sum('bts_diproses');
        $summaryOer = $this->computeRate((float) $records->sum('produksi_cpo'), $summaryBtsDiproses);
        $summaryKer = $this->computeRate((float) $records->sum('produksi_pk'), $summaryBtsDiproses);

        $operatedDays = (clone $query)->operated()->count();
        $referenceDays = $this->resolveReportReferenceDays($request);

        return view('laporan.index', compact('mills', 'records', 'operatedDays', 'referenceDays', 'summaryOer', 'summaryKer', 'reportPeriodTitle'));
    }

    /**
     * Export Excel - guna format CSV (boleh dibuka terus dalam Excel, tak perlu package tambahan).
     * Boleh upgrade ke .xlsx sebenar dengan package maatwebsite/excel kemudian.
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $records = $this->filteredQuery($request)->with('mill')->orderBy('tarikh')->get();

        AuditLog::record('export_excel');

        $filename = 'laporan_ppnj_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        return response()->streamDownload(function () use ($records) {
            echo "\xEF\xBB\xBF"; // BOM untuk Excel baca UTF-8 dengan betul
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Tarikh', 'Kilang', 'Shift', 'BTS Diterima', 'BTS Diproses', 'Jualan CPO', 'Jualan PK', 'Produksi CPO', 'Produksi PK', 'OER%', 'KER%', 'FFA%', 'Downtime (jam)', 'Utilisation%']);
            foreach ($records as $r) {
                fputcsv($out, [
                    $r->tarikh->format('d/m/Y'), $r->mill->name, $r->shift,
                    $r->bts_diterima, $r->bts_diproses, $r->pengeluaran_cpo, $r->pengeluaran_pk,
                    $r->produksi_cpo, $r->produksi_pk,
                    $r->oer, $r->ker, $r->ffa, $r->downtime_jam, $r->utilisation_rate,
                ]);
            }
            fclose($out);
        }, $filename, $headers);
    }

    /**
     * Export PDF - paparan print-friendly. Guna butang "Print" / "Save as PDF" browser.
     * Boleh upgrade ke barryvdh/laravel-dompdf untuk auto-generate PDF kemudian.
     */
    public function exportPdf(Request $request)
    {
        $query = $this->filteredQuery($request);
        $records = (clone $query)->with('mill')->orderBy('tarikh')->get();
        $reportMillName = $this->resolveReportMillName($request, $records);
        $reportPeriodTitle = $this->resolveReportPeriodTitle($request);
        $summaryBtsDiproses = (float) $records->sum('bts_diproses');
        $summaryOer = $this->computeRate((float) $records->sum('produksi_cpo'), $summaryBtsDiproses);
        $summaryKer = $this->computeRate((float) $records->sum('produksi_pk'), $summaryBtsDiproses);
        $operatedDays = (clone $query)->operated()->count();
        $referenceDays = $this->resolveReportReferenceDays($request);

        AuditLog::record('export_pdf');

        return view('laporan.print', compact('records', 'operatedDays', 'referenceDays', 'summaryOer', 'summaryKer', 'reportMillName', 'reportPeriodTitle'));
    }

    private function filteredQuery(Request $request)
    {
        $user = $request->user();
        $query = DailyOperation::query();

        if ($user->isMillScopedRole()) {
            $query->where('mill_id', $user->mill_id);
        } elseif ($request->filled('mill_id')) {
            $query->where('mill_id', $request->input('mill_id'));
        }

        if ($request->filled('tarikh_mula')) {
            $query->where('tarikh', '>=', $request->input('tarikh_mula'));
        }
        if ($request->filled('tarikh_akhir')) {
            $query->where('tarikh', '<=', $request->input('tarikh_akhir'));
        }
        if ($request->filled('bulan')) {
            $query->whereMonth('tarikh', $request->input('bulan'));
        }
        if ($request->filled('tahun')) {
            $query->whereYear('tarikh', $request->input('tahun'));
        }

        return $query;
    }

    private function resolveReportReferenceDays(Request $request): int
    {
        if ($request->filled('tarikh_akhir')) {
            return Carbon::parse($request->input('tarikh_akhir'))->day;
        }

        if ($request->filled('bulan') && $request->filled('tahun')) {
            return Carbon::create($request->input('tahun'), $request->input('bulan'))->daysInMonth;
        }

        if ($request->filled('bulan')) {
            return Carbon::create(now()->year, $request->input('bulan'))->daysInMonth;
        }

        if ($request->filled('tahun')) {
            return Carbon::now()->year === (int) $request->input('tahun')
                ? Carbon::yesterday()->day
                : Carbon::create($request->input('tahun'), 12, 1)->daysInMonth;
        }

        return Carbon::yesterday()->day;
    }

    private function computeRate(float $production, float $btsDiproses): float
    {
        if ($btsDiproses <= 0) {
            return 0.0;
        }

        return round(($production / $btsDiproses) * 100, 2);
    }

    private function resolveReportMillName(Request $request, $records): string
    {
        $user = $request->user();

        if ($user->isMillScopedRole() && $user->mill) {
            return $user->mill->name;
        }

        if ($request->filled('mill_id')) {
            $mill = Mill::find($request->input('mill_id'));
            if ($mill) {
                return $mill->name;
            }
        }

        if ($records->pluck('mill_id')->unique()->count() === 1) {
            return $records->first()?->mill?->name ?? 'Gabungan Semua Kilang';
        }

        return 'Gabungan Semua Kilang';
    }

    private function resolveReportPeriodTitle(Request $request): string
    {
        $startDate = null;
        $endDate = null;

        if ($request->filled('tarikh_mula') && $request->filled('tarikh_akhir')) {
            $startDate = Carbon::parse($request->input('tarikh_mula'))->startOfDay();
            $endDate = Carbon::parse($request->input('tarikh_akhir'))->startOfDay();
        } elseif ($request->filled('bulan') && $request->filled('tahun')) {
            $startDate = Carbon::create((int) $request->input('tahun'), (int) $request->input('bulan'), 1)->startOfDay();
            $endDate = $startDate->copy()->endOfMonth()->startOfDay();
        } elseif ($request->filled('bulan')) {
            $startDate = Carbon::create(now()->year, (int) $request->input('bulan'), 1)->startOfDay();
            $endDate = $startDate->copy()->endOfMonth()->startOfDay();
        } elseif ($request->filled('tahun')) {
            $startDate = Carbon::create((int) $request->input('tahun'), 1, 1)->startOfDay();
            $endDate = Carbon::create((int) $request->input('tahun'), 12, 31)->startOfDay();
        }

        if (! $startDate || ! $endDate) {
            return 'Laporan Bagi Tempoh Data Dipilih';
        }

        $isSameMonth = $startDate->year === $endDate->year && $startDate->month === $endDate->month;
        $isFullMonth = $isSameMonth
            && $startDate->isSameDay($startDate->copy()->startOfMonth())
            && $endDate->isSameDay($startDate->copy()->endOfMonth()->startOfDay());

        if ($isFullMonth) {
            return 'Laporan Bulan ' . $startDate->translatedFormat('F Y');
        }

        return 'Laporan Bagi Tempoh '
            . $startDate->translatedFormat('j M Y')
            . ' hingga '
            . $endDate->translatedFormat('j M Y');
    }
}
