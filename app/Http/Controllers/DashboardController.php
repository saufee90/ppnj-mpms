<?php

namespace App\Http\Controllers;

use App\Models\DailyOperation;
use App\Models\KpiTarget;
use App\Models\Mill;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $mills = $user->isMillScopedRole()
            ? Mill::where('id', $user->mill_id)->get()
            : Mill::where('is_active', true)->get();

        $selectedMillId = $user->isMillScopedRole()
            ? $user->mill_id
            : $request->input('mill_id');

        // Untuk paparan metrik Harian gunakan data semalam (T+1 workflow)
        $displayDate = Carbon::yesterday()->toDateString();

        $baseQuery = DailyOperation::query();
        if ($selectedMillId) {
            $baseQuery->where('mill_id', $selectedMillId);
        }

        $isAllMillsSelected = empty($selectedMillId);
        $canViewComparisonCharts = $user->canViewAllMills();

        $latestOperationRecord = (clone $baseQuery)
            ->orderByDesc('tarikh')
            ->orderByDesc('id')
            ->first();

        $latestOperationDateText = $latestOperationRecord?->tarikh
            ? $latestOperationRecord->tarikh->translatedFormat('d F Y')
            : '-';

        $lastUpdatedText = $latestOperationRecord?->updated_at
            ? $latestOperationRecord->updated_at->translatedFormat('d F Y, H:i')
            : '-';

        $operatedDays = (clone $baseQuery)
            ->forMonth(now()->year, now()->month)
            ->operated()
            ->count();

        $referenceDays = Carbon::yesterday()->day;

        // Metrik Harian: gunakan data semalam
        $todayData = (clone $baseQuery)->where('tarikh', $displayDate)->get();

        // Status Operasi Kilang: guna rekod terbaru sehingga tarikh paparan semasa
        $latestOperationalRecord = (clone $baseQuery)
            ->where('tarikh', '<=', $displayDate)
            ->orderByDesc('tarikh')
            ->orderByDesc('id')
            ->first();

        $operationalStatus = [
            'baki_bts_selepas_diproses' => (float) ($latestOperationalRecord?->baki_bts_selepas_diproses ?? 0),
            'stok_cpo_semalam' => (float) ($latestOperationalRecord?->stok_cpo_yesterday ?? 0),
        ];

        $statusMills = Mill::query()
            ->whereIn('code', ['KHG', 'BBJ'])
            ->orderByRaw("CASE code WHEN 'KHG' THEN 1 WHEN 'BBJ' THEN 2 ELSE 99 END")
            ->get();

        $operationalStatusByMill = $statusMills->map(function ($mill) use ($displayDate) {
            $latestMillRecord = DailyOperation::where('mill_id', $mill->id)
                ->where('tarikh', '<=', $displayDate)
                ->orderByDesc('tarikh')
                ->orderByDesc('id')
                ->first();

            return [
                'mill_id' => $mill->id,
                'code' => $mill->code,
                'name' => $mill->name,
                'source_tarikh' => $latestMillRecord?->tarikh?->toDateString(),
                'source_tarikh_text' => $latestMillRecord?->tarikh
                    ? $latestMillRecord->tarikh->translatedFormat('d F Y')
                    : '-',
                'baki_bts_selepas_diproses' => (float) ($latestMillRecord?->baki_bts_selepas_diproses ?? 0),
                'stok_cpo_semalam' => (float) ($latestMillRecord?->stok_cpo_yesterday ?? 0),
            ];
        });

        if ($selectedMillId) {
            $operationalStatusByMill = $operationalStatusByMill
                ->where('mill_id', (int) $selectedMillId)
                ->values();
        }

        $summary = [
            'bts_diterima' => $todayData->sum('bts_diterima'),
            'bts_diproses' => $todayData->sum('bts_diproses'),
            'jual_cpo' => $todayData->sum('pengeluaran_cpo'),
            'jual_pk' => $todayData->sum('pengeluaran_pk'),
            'produksi_cpo' => $todayData->sum('produksi_cpo'),
            'produksi_pk' => $todayData->sum('produksi_pk'),
            'oer' => $this->computeRateFromRows($todayData, 'produksi_cpo'),
            'ker' => $this->computeRateFromRows($todayData, 'produksi_pk'),
            'downtime' => $todayData->sum('downtime_jam'),
        ];

        // Bilangan rekod yang masih tertunggak data kualiti (untuk alert)
        $qualityPendingQuery = DailyOperation::where(function ($query) {
            $query->whereNull('ffa')
                  ->orWhereNull('moisture')
                  ->orWhereNull('dirt');
        });
        if ($user->isMillScopedRole()) {
            $qualityPendingQuery->where('mill_id', $user->mill_id);
        } elseif ($selectedMillId) {
            $qualityPendingQuery->where('mill_id', $selectedMillId);
        }
        $qualityPendingCount = $qualityPendingQuery->count();

        $millsBelumHantar = collect();
        foreach ($mills as $mill) {
            // Semak penghantaran untuk tarikh semalam
            $exists = DailyOperation::where('mill_id', $mill->id)->where('tarikh', $displayDate)->exists();
            if (! $exists) {
                $millsBelumHantar->push($mill->name);
            }
        }

        $trendStart = now()->startOfMonth()->toDateString();
        $trendEnd = now()->toDateString();
        $trendData = (clone $baseQuery)
            ->whereBetween('tarikh', [$trendStart, $trendEnd])
            ->orderBy('tarikh')
            ->get()
            ->groupBy(fn ($row) => $row->tarikh->toDateString());

        $labels = [];
        $btsProcessedTrend = [];
        $oerTrend = [];
        $kerTrend = [];
        $downtimeTrend = [];

        $cursor = Carbon::parse($trendStart);
        $endCursor = Carbon::parse($trendEnd);

        while ($cursor->lte($endCursor)) {
            $date = $cursor->toDateString();
            $labels[] = $cursor->format('d/m');
            $rows = $trendData->get($date, collect());
            $btsProcessedTrend[] = round($rows->sum('bts_diproses'), 2);
            $oerTrend[] = round($this->computeRateFromRows($rows, 'produksi_cpo'), 2);
            $kerTrend[] = round($this->computeRateFromRows($rows, 'produksi_pk'), 2);
            $downtimeTrend[] = round((float) $rows->sum('downtime_jam'), 2);

            $cursor->addDay();
        }

        $target = KpiTarget::getActiveTarget($selectedMillId, now()->year);

        $alertMessages = [];
        foreach ($mills as $mill) {
            $millTodayData = $todayData->where('mill_id', $mill->id);
            if ($millTodayData->isEmpty()) {
                continue;
            }

            $millName = "Kilang Sawit PPNJ {$mill->name}";
            $millOer = $this->computeRateFromRows($millTodayData, 'produksi_cpo');
            $millDowntime = $millTodayData->sum('downtime_jam');

            if ($millOer > 0 && $millOer < $target->oer_target) {
                $alertMessages[] = "🔻 OER {$millName} hari ini (" . number_format($millOer, 2) . "%) berada di bawah sasaran (" . number_format($target->oer_target, 2) . "%).";
            }

            if ($millDowntime > $target->downtime_max_hours) {
                $alertMessages[] = "⏱️ Downtime {$millName} hari ini (" . number_format($millDowntime, 2) . " jam) melebihi had yang ditetapkan (" . number_format($target->downtime_max_hours, 2) . " jam).";
            }
        }

        $mtd = (clone $baseQuery)->forMonth(now()->year, now()->month)->get();
        $ytd = (clone $baseQuery)->forYear(now()->year)->get();

        // Kalkulasi metrik Daily/MTD/YTD untuk 6 KPI utama
        $dailyMetrics = [
            'bts_diterima' => $todayData->sum('bts_diterima'),
            'bts_diproses' => $todayData->sum('bts_diproses'),
            'penjualan_cpo' => $todayData->sum('pengeluaran_cpo'),
            'penjualan_pk' => $todayData->sum('pengeluaran_pk'),
            'produksi_cpo' => $todayData->sum('produksi_cpo'),
            'produksi_pk' => $todayData->sum('produksi_pk'),
            'baki_bts_semalam' => $todayData->sum('baki_bts_semalam'),
            'baki_bts_selepas_diproses' => $todayData->sum('baki_bts_selepas_diproses'),
            'oer_rata' => $this->computeRateFromRows($todayData, 'produksi_cpo'),
            'ker_rata' => $this->computeRateFromRows($todayData, 'produksi_pk'),
            'jam_proses' => $todayData->sum('jam_operasi'),
        ];

        $mtdBtsDiproses = $mtd->sum('bts_diproses');
        $mtdProduksiCpo = $mtd->sum('produksi_cpo');
        $mtdProduksiPk = $mtd->sum('produksi_pk');

        $mtdMetrics = [
            'bts_diterima' => $mtd->sum('bts_diterima'),
            'bts_diproses' => $mtdBtsDiproses,
            'pengeluaran_cpo' => $mtd->sum('pengeluaran_cpo'),
            'pengeluaran_pk' => $mtd->sum('pengeluaran_pk'),
            'produksi_cpo' => $mtdProduksiCpo,
            'produksi_pk' => $mtdProduksiPk,
            'baki_bts_semalam' => $mtd->sum('baki_bts_semalam'),
            'baki_bts_selepas_diproses' => $mtd->sum('baki_bts_selepas_diproses'),
            'oer_rata' => $this->computeRate($mtdProduksiCpo, $mtdBtsDiproses),
            'ker_rata' => $this->computeRate($mtdProduksiPk, $mtdBtsDiproses),
            'jam_proses' => $mtd->sum('jam_operasi'),
        ];

        $ytdBtsDiproses = $ytd->sum('bts_diproses');
        $ytdProduksiCpo = $ytd->sum('produksi_cpo');
        $ytdProduksiPk = $ytd->sum('produksi_pk');

        $ytdMetrics = [
            'bts_diterima' => $ytd->sum('bts_diterima'),
            'bts_diproses' => $ytdBtsDiproses,
            'pengeluaran_cpo' => $ytd->sum('pengeluaran_cpo'),
            'pengeluaran_pk' => $ytd->sum('pengeluaran_pk'),
            'produksi_cpo' => $ytdProduksiCpo,
            'produksi_pk' => $ytdProduksiPk,
            'baki_bts_semalam' => $ytd->sum('baki_bts_semalam'),
            'baki_bts_selepas_diproses' => $ytd->sum('baki_bts_selepas_diproses'),
            'oer_rata' => $this->computeRate($ytdProduksiCpo, $ytdBtsDiproses),
            'ker_rata' => $this->computeRate($ytdProduksiPk, $ytdBtsDiproses),
            'jam_proses' => $ytd->sum('jam_operasi'),
        ];

        return view('dashboard.index', [
            'mills' => $mills,
            'selectedMillId' => $selectedMillId,
            'isAllMillsSelected' => $isAllMillsSelected,
            'canViewComparisonCharts' => $canViewComparisonCharts,
            'latestOperationDateText' => $latestOperationDateText,
            'lastUpdatedText' => $lastUpdatedText,
            'operationalStatus' => $operationalStatus,
            'operationalStatusByMill' => $operationalStatusByMill,
            'summary' => $summary,
            'millsBelumHantar' => $millsBelumHantar,
            'labels' => $labels,
            'btsProcessedTrend' => $btsProcessedTrend,
            'oerTrend' => $oerTrend,
            'kerTrend' => $kerTrend,
            'downtimeTrend' => $downtimeTrend,
            'target' => $target,
            'qualityPendingCount' => $qualityPendingCount,
            'dailyMetrics' => $dailyMetrics,
            'mtdMetrics' => $mtdMetrics,
            'ytdMetrics' => $ytdMetrics,
            'operatedDays' => $operatedDays,
            'referenceDays' => $referenceDays,
            'mtdCpo' => $mtd->sum('pengeluaran_cpo'),
            'mtdBts' => $mtdBtsDiproses,
            'ytdCpo' => $ytd->sum('pengeluaran_cpo'),
            'ytdBts' => $ytdBtsDiproses,
        ]);
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
        $production = (float) $rows->sum($productionField);
        $btsDiproses = (float) $rows->sum('bts_diproses');

        return $this->computeRate($production, $btsDiproses);
    }
}
