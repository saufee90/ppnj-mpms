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

        $todayLatestRecord = $todayData->sortByDesc('id')->first();
        $isReceivedButNoProcessDay = (bool) $selectedMillId
            && $todayLatestRecord?->operation_status === 'tidak_operasi_terima_bts';
        $isNoRecordDay = (bool) $selectedMillId && $todayData->isEmpty();

        $lastOperatedRecord = $selectedMillId
            ? $this->getLastOperatingRecord((int) $selectedMillId, $displayDate)
            : null;

        $isUsingLastOperatingKpi = (bool) $selectedMillId && ! $this->isOperatingRecord($todayLatestRecord);

        $operationalKpiSourceDateText = $lastOperatedRecord?->tarikh
            ? $lastOperatedRecord->tarikh->format('d/m/Y')
            : '-';

        // Status Operasi Kilang: guna rekod terbaru sehingga tarikh paparan semasa
        $latestOperationalRecord = (clone $baseQuery)
            ->where('tarikh', '<=', $displayDate)
            ->orderByDesc('tarikh')
            ->orderByDesc('id')
            ->first();

        // Label tarikh untuk metrik "semalam" adalah sehari sebelum tarikh paparan dashboard.
        $operationalStatusDate = Carbon::parse($displayDate)->subDay()->translatedFormat('d F Y');

        $operationalStatus = [
            'baki_bts_selepas_diproses' => (float) ($latestOperationalRecord?->baki_bts_selepas_diproses ?? 0),
            'stok_cpo_semalam' => (float) ($latestOperationalRecord?->stok_cpo_yesterday ?? 0),
        ];

        $statusMills = $selectedMillId
            ? Mill::where('id', (int) $selectedMillId)->get()
            : Mill::where('is_active', true)->orderBy('name')->get();

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
            $oerTrend[] = round($this->computeRateFromRows($rows, 'produksi_cpo'), 2);
            $kerTrend[] = round($this->computeRateFromRows($rows, 'produksi_pk'), 2);
        }

        $chartMills = $selectedMillId
            ? $mills->where('id', (int) $selectedMillId)->values()
            : $mills->values();

        $chartOperationDate = (clone $baseQuery)
            ->orderByDesc('tarikh')
            ->orderByDesc('id')
            ->first()?->tarikh?->toDateString();

        $comparisonLabels = $chartMills->pluck('name');
        $comparisonBts = $chartMills->map(function ($mill) use ($chartOperationDate) {
            if (! $chartOperationDate) {
                return 0;
            }

            return DailyOperation::where('mill_id', $mill->id)
                ->whereDate('tarikh', $chartOperationDate)
                ->sum('bts_diproses');
        });
        $comparisonDowntime = $chartMills->map(function ($mill) use ($chartOperationDate) {
            if (! $chartOperationDate) {
                return 0;
            }

            return DailyOperation::where('mill_id', $mill->id)
                ->whereDate('tarikh', $chartOperationDate)
                ->sum('downtime_jam');
        });

        $downtimeTrendByMill = collect();
        if (! $canViewComparisonCharts) {
            $recentMillDowntime = (clone $baseQuery)
                ->orderByDesc('tarikh')
                ->orderByDesc('id')
                ->get()
                ->groupBy(fn ($row) => $row->tarikh->toDateString())
                ->take(14)
                ->reverse();

            $downtimeTrendByMill = [
                'labels' => $recentMillDowntime->keys()
                    ->map(fn ($date) => Carbon::parse($date)->format('d/m'))
                    ->values(),
                'values' => $recentMillDowntime->map(function ($rows) {
                    return round((float) $rows->sum('downtime_jam'), 2);
                })->values(),
            ];
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

        // KPI operasi harian: guna rekod hari semasa jika operasi, selainnya fallback ke Hari Operasi Terakhir.
        $operationalSourceRows = collect();
        if ($selectedMillId) {
            if ($this->isOperatingRecord($todayLatestRecord)) {
                $operationalSourceRows = collect([$todayLatestRecord]);
            } elseif ($lastOperatedRecord) {
                $operationalSourceRows = collect([$lastOperatedRecord]);
            }
        } else {
            foreach ($mills as $mill) {
                $millTodayRecord = DailyOperation::where('mill_id', $mill->id)
                    ->where('tarikh', $displayDate)
                    ->orderByDesc('id')
                    ->first();

                if ($this->isOperatingRecord($millTodayRecord)) {
                    $operationalSourceRows->push($millTodayRecord);
                    continue;
                }

                $millLastOperating = $this->getLastOperatingRecord((int) $mill->id, $displayDate);
                if ($millLastOperating) {
                    $operationalSourceRows->push($millLastOperating);
                }
            }
        }

        $operationalBtsDiproses = (float) $operationalSourceRows->sum('bts_diproses');
        $operationalJamProses = (float) $operationalSourceRows->sum('jam_operasi');

        $dailyOperationalKpis = [
            'penjualan_cpo' => (float) $operationalSourceRows->sum('pengeluaran_cpo'),
            'penjualan_pk' => (float) $operationalSourceRows->sum('pengeluaran_pk'),
            'produksi_cpo' => (float) $operationalSourceRows->sum('produksi_cpo'),
            'produksi_pk' => (float) $operationalSourceRows->sum('produksi_pk'),
            'oer_rata' => $this->computeRate((float) $operationalSourceRows->sum('produksi_cpo'), $operationalBtsDiproses),
            'ker_rata' => $this->computeRate((float) $operationalSourceRows->sum('produksi_pk'), $operationalBtsDiproses),
            'jam_proses' => $operationalJamProses,
            'throughput' => $operationalJamProses > 0 ? round($operationalBtsDiproses / $operationalJamProses, 2) : 0.0,
            'utilisation' => (float) ($operationalSourceRows->avg('utilisation_rate') ?? 0),
        ];

        $dailyMetrics = [
            'bts_diterima' => $todayData->sum('bts_diterima'),
            'bts_diproses' => $todayData->sum('bts_diproses'),
            'penjualan_cpo' => $dailyOperationalKpis['penjualan_cpo'],
            'penjualan_pk' => $dailyOperationalKpis['penjualan_pk'],
            'produksi_cpo' => $dailyOperationalKpis['produksi_cpo'],
            'produksi_pk' => $dailyOperationalKpis['produksi_pk'],
            'baki_bts_semalam' => $todayData->sum('baki_bts_semalam'),
            'baki_bts_selepas_diproses' => $todayData->sum('baki_bts_selepas_diproses'),
            'oer_rata' => $dailyOperationalKpis['oer_rata'],
            'ker_rata' => $dailyOperationalKpis['ker_rata'],
            'jam_proses' => $dailyOperationalKpis['jam_proses'],
            'throughput' => $dailyOperationalKpis['throughput'],
            'utilisation' => $dailyOperationalKpis['utilisation'],
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
            'operationalStatusDate' => $operationalStatusDate,
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
            'downtimeTrendByMill' => $downtimeTrendByMill,
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
            'isReceivedButNoProcessDay' => $isReceivedButNoProcessDay,
            'isNoRecordDay' => $isNoRecordDay,
            'isUsingLastOperatingKpi' => $isUsingLastOperatingKpi,
            'operationalKpiSourceDateText' => $operationalKpiSourceDateText,
        ]);
    }

    private function getLastOperatingRecord(int $millId, string $selectedDate): ?DailyOperation
    {
        return DailyOperation::where('mill_id', $millId)
            ->where('tarikh', '<', $selectedDate)
            ->where(function ($query) {
                $query->where('operation_status', 'operasi')
                    ->orWhere(function ($legacyQuery) {
                        $legacyQuery->whereNull('operation_status')
                            ->where('bts_diproses', '>', 0)
                            ->where('jam_operasi', '>', 0);
                    });
            })
            ->orderByDesc('tarikh')
            ->orderByDesc('id')
            ->first();
    }

    private function isOperatingRecord($record): bool
    {
        if (! $record) {
            return false;
        }

        if (($record->operation_status ?? null) === 'operasi') {
            return true;
        }

        if ($record->operation_status === null) {
            return (float) ($record->bts_diproses ?? 0) > 0 && (float) ($record->jam_operasi ?? 0) > 0;
        }

        return false;
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
