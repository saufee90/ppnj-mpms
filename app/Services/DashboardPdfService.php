<?php

namespace App\Services;

use App\Models\DailyOperation;
use App\Models\Mill;
use Carbon\Carbon;

class DashboardPdfService
{
    public function generate(?Carbon $displayDate = null): array
    {
        $displayDate ??= Carbon::yesterday();

        $statusMills = Mill::query()
            ->whereIn('code', ['KHG', 'BBJ'])
            ->orderByRaw("CASE code WHEN 'KHG' THEN 1 WHEN 'BBJ' THEN 2 ELSE 99 END")
            ->get();

        $millSummaries = $statusMills->map(function ($mill) use ($displayDate) {
            $dailyQuery = DailyOperation::query()
                ->where('mill_id', $mill->id)
                ->whereDate('tarikh', $displayDate->toDateString());

            $dailyRows = $dailyQuery->get();
            $latestDailyRecord = $dailyRows->sortByDesc('id')->first();

            $mtdQuery = DailyOperation::query()
                ->where('mill_id', $mill->id)
                ->forMonth($displayDate->year, $displayDate->month);

            $mtdRows = $mtdQuery->get();
            $mtdOperatedDays = (clone $mtdQuery)->operated()->count();

            $dailyBtsDiproses = (float) $dailyRows->sum('bts_diproses');
            $mtdBtsDiproses = (float) $mtdRows->sum('bts_diproses');
            $mtdProduksiCpo = (float) $mtdRows->sum('produksi_cpo');
            $mtdProduksiPk = (float) $mtdRows->sum('produksi_pk');

            return [
                'mill_id' => $mill->id,
                'code' => $mill->code,
                'name' => $mill->name,
                'status' => $this->resolveOperationStatus($latestDailyRecord),
                'data_date_text' => $latestDailyRecord?->tarikh
                    ? $latestDailyRecord->tarikh->translatedFormat('d F Y')
                    : 'Tiada Data',

                'bts_diterima' => (float) $dailyRows->sum('bts_diterima'),
                'bts_diproses' => $dailyBtsDiproses,
                'pengeluaran_cpo' => (float) $dailyRows->sum('produksi_cpo'),
                'jualan_cpo' => (float) $dailyRows->sum('pengeluaran_cpo'),
                'stok_cpo' => (float) ($latestDailyRecord?->stok_cpo ?? 0),
                'pengeluaran_pk' => (float) $dailyRows->sum('produksi_pk'),
                'jualan_pk' => (float) $dailyRows->sum('pengeluaran_pk'),
                'stok_pk' => (float) ($latestDailyRecord?->stok_pk ?? 0),
                'oer' => $this->computeRateFromRows($dailyRows, 'produksi_cpo'),
                'ker' => $this->computeRateFromRows($dailyRows, 'produksi_pk'),
                'throughput' => $this->computeThroughputFromRows($dailyRows),
                'downtime' => (float) $dailyRows->sum('downtime_jam'),
                'baki_bts_selepas_diproses' => (float) ($latestDailyRecord?->baki_bts_selepas_diproses ?? 0),

                'mtd' => [
                    'bts_diterima' => (float) $mtdRows->sum('bts_diterima'),
                    'bts_diproses' => $mtdBtsDiproses,
                    'pengeluaran_cpo' => (float) $mtdRows->sum('produksi_cpo'),
                    'pengeluaran_pk' => (float) $mtdRows->sum('produksi_pk'),
                    'oer' => $this->computeRate($mtdProduksiCpo, $mtdBtsDiproses),
                    'ker' => $this->computeRate($mtdProduksiPk, $mtdBtsDiproses),
                    'downtime' => (float) $mtdRows->sum('downtime_jam'),
                    'hari_operasi' => $mtdOperatedDays,
                ],
            ];
        })->values();

        $filename = 'MPS_Daily_Report_' . $displayDate->translatedFormat('d_F_Y') . '.pdf';

        $pdf = app('dompdf.wrapper')->loadView('dashboard.pdf', [
            'logoDataUri' => $this->makeImageDataUri(public_path('images/logo-ppnj.jpg')),
            'displayDateText' => $displayDate->translatedFormat('d F Y'),
            'generatedAtText' => now()->translatedFormat('d F Y, H:i'),
            'millSummaries' => $millSummaries,
            'attentionMessages' => $this->buildAttentionMessages($millSummaries),
        ])->setPaper('a4', 'landscape');

        return [
            'pdf' => $pdf,
            'content' => $pdf->output(),
            'filename' => $filename,
            'display_date' => $displayDate,
            'mill_summaries' => $millSummaries,
        ];
    }

    private function buildAttentionMessages($millSummaries): array
    {
        $messages = [];

        foreach ($millSummaries as $summary) {
            if (($summary['downtime'] ?? 0) > 10) {
                $messages[] = 'Downtime Kilang ' . $summary['name'] . ' melebihi 10 jam.';
            }
        }

        if (! empty($messages)) {
            return $messages;
        }

        foreach ($millSummaries as $summary) {
            $status = $summary['status'] ?? 'Tiada Data';

            if ($status !== 'Operasi' && $status !== 'Tiada Data') {
                $messages[] = 'Kilang ' . $summary['name']
                    . ' tidak beroperasi pada tarikh data (' . $status . ').';
            }
        }

        if (empty($messages)) {
            $messages[] = 'Tiada isu kritikal berdasarkan data harian semasa.';
        }

        return $messages;
    }

    private function resolveOperationStatus(?DailyOperation $record): string
    {
        if (! $record) {
            return 'Tiada Data';
        }

        if (! empty($record->operation_status)) {
            return $record->operation_status;
        }

        if (
            (float) ($record->bts_diproses ?? 0) > 0
            || (float) ($record->jam_operasi ?? 0) > 0
        ) {
            return 'Operasi';
        }

        return 'Tidak Operasi';
    }

    private function computeThroughputFromRows($rows): float
    {
        $btsDiproses = (float) $rows->sum('bts_diproses');
        $jamOperasi = (float) $rows->sum('jam_operasi');

        if ($jamOperasi <= 0) {
            return 0.0;
        }

        return round($btsDiproses / $jamOperasi, 2);
    }

    private function makeImageDataUri(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/jpeg';

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }

    private function computeRate(float|int $production, float|int $btsDiproses): float
    {
        $btsDiproses = (float) $btsDiproses;

        if ($btsDiproses <= 0) {
            return 0.0;
        }

        return round(((float) $production / $btsDiproses) * 100, 2);
    }

    private function computeRateFromRows($rows, string $productionField): float
    {
        return $this->computeRate(
            (float) $rows->sum($productionField),
            (float) $rows->sum('bts_diproses')
        );
    }
}