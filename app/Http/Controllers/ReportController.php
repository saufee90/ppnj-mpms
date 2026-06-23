<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DailyOperation;
use App\Models\Mill;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $mills = Mill::where('is_active', true)->get();
        $records = $this->filteredQuery($request)->with('mill')->orderBy('tarikh')->get();

        return view('laporan.index', compact('mills', 'records'));
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
            fputcsv($out, ['Tarikh', 'Kilang', 'Shift', 'BTS Diterima', 'BTS Diproses', 'CPO', 'PK', 'OER%', 'KER%', 'FFA%', 'Downtime (jam)', 'Utilisation%']);
            foreach ($records as $r) {
                fputcsv($out, [
                    $r->tarikh->format('d/m/Y'), $r->mill->name, $r->shift,
                    $r->bts_diterima, $r->bts_diproses, $r->pengeluaran_cpo, $r->pengeluaran_pk,
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
        $records = $this->filteredQuery($request)->with('mill')->orderBy('tarikh')->get();

        AuditLog::record('export_pdf');

        return view('laporan.print', compact('records'));
    }

    private function filteredQuery(Request $request)
    {
        $user = $request->user();
        $query = DailyOperation::query();

        if ($user->isPegawaiKilang()) {
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
}
