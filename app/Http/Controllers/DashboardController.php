<?php

namespace App\Http\Controllers;

use App\Models\DailyOperation;
use App\Models\Mill;
use App\Models\MpobPriceHistory;
use App\Services\KpiEvaluationService;
use App\Services\MpobPriceService;
use App\Services\DashboardWeatherService;
use App\Services\DashboardKpiProgressService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use App\Services\DashboardPdfService;
use App\Services\YtdPerformanceService;

class DashboardController extends Controller
{
    public function __construct(
        private readonly KpiEvaluationService $kpiEvaluationService,
        private readonly YtdPerformanceService $ytdPerformanceService,
        private readonly DashboardKpiProgressService $dashboardKpiProgressService
    ) {
    }

    public function index(Request $request, MpobPriceService $mpobPriceService)
    {
        $user = $request->user();
        $mills = $user->isMillScopedRole()
            ? Mill::where('id', $user->mill_id)->get()
            : Mill::where('is_active', true)
                ->when(
                    $user->isEksekutifPemasaranBts(),
                    fn ($query) => $query->whereIn('code', ['BBJ', 'KHG'])
                )
                ->get();

        $selectedMillId = $user->isMillScopedRole()
            ? $user->mill_id
            : ($user->canSelectDashboardMill() ? $request->input('mill_id') : null);
        if (! $user->isMillScopedRole()
            && $selectedMillId
            && ! $mills->contains('id', (int) $selectedMillId)) {
            $selectedMillId = null;
        }

        $baseQuery = DailyOperation::query();
        if ($user->isMillScopedRole()) {
            $baseQuery->where('mill_id', $user->mill_id);
        } elseif ($user->isEksekutifPemasaranBts()) {
            $baseQuery->whereIn('mill_id', $mills->pluck('id'));
            if ($selectedMillId) {
                $baseQuery->where('mill_id', (int) $selectedMillId);
            }
        } elseif ($user->canSelectDashboardMill()
            && $request->filled('mill_id')
            && $mills->contains('id', (int) $request->input('mill_id'))) {
            $baseQuery->where('mill_id', (int) $request->input('mill_id'));
        }

        $latestOperationRecord = (clone $baseQuery)
            ->whereDate('tarikh', '<=', Carbon::yesterday()->toDateString())
            ->orderByDesc('tarikh')
            ->orderByDesc('id')
            ->first();

        $requestedDate = null;
        if ($request->filled('tarikh')) {
            try {
                $candidateDate = Carbon::createFromFormat('!Y-m-d', (string) $request->input('tarikh'));
                if ($candidateDate->format('Y-m-d') === $request->input('tarikh')
                    && $candidateDate->lte(Carbon::yesterday())) {
                    $requestedDate = $candidateDate->toDateString();
                }
            } catch (\Throwable) {
                $requestedDate = null;
            }
        }

        $displayDate = $requestedDate
            ?? $latestOperationRecord?->tarikh?->toDateString()
            ?? Carbon::yesterday()->toDateString();
        $latestOperationRecord = (clone $baseQuery)
            ->whereDate('tarikh', '<=', $displayDate)
            ->orderByDesc('tarikh')
            ->orderByDesc('id')
            ->first();
        $displayDateCarbon = Carbon::parse($displayDate);

        $selectedPeriod = in_array($request->query('period'), ['harian', 'mtd', 'ytd'], true)
            ? $request->query('period')
            : 'harian';

        $isAllMillsSelected = empty($selectedMillId);
        $canViewComparisonCharts = $user->canViewAllMills();

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
        $todayData = (clone $baseQuery)->whereDate('tarikh', $displayDate)->get();

        // Status Operasi Kilang: guna rekod terbaru sehingga tarikh paparan semasa
        $latestOperationalRecord = (clone $baseQuery)
            ->where('tarikh', '<=', $displayDate)
            ->orderByDesc('tarikh')
            ->orderByDesc('id')
            ->first();

        $operationalStatus = [
            'baki_bts_selepas_diproses' => $latestOperationalRecord?->baki_bts_selepas_diproses,
            'stok_cpo' => $latestOperationalRecord?->stok_cpo,
        ];

        $mpobPrices = [
            'products' => [
                'cpo' => ['name' => 'Crude Palm Oil (CPO)', 'price' => null, 'price_date' => null],
                'pk' => ['name' => 'Palm Kernel (PK)', 'price' => null, 'price_date' => null],
                'cpko' => ['name' => 'Crude Palm Kernel Oil (CPKO)', 'price' => null, 'price_date' => null],
            ],
            'mpob_last_update' => null,
            'source_url' => MpobPriceService::SOURCE_URL,
            'refreshed_at' => null,
            'checked_at' => null,
            'source_available' => false,
        ];

        try {
            $mpobPrices = $mpobPriceService->getForDashboard();
        } catch (\Throwable $exception) {
            report($exception);
        }

        $mpobTrendData = [
            'cpo' => ['labels' => [], 'values' => [], 'delta' => ['symbol' => '—', 'amount' => 0.0]],
            'pk' => ['labels' => [], 'values' => [], 'delta' => ['symbol' => '—', 'amount' => 0.0]],
            'cpko' => ['labels' => [], 'values' => [], 'delta' => ['symbol' => '—', 'amount' => 0.0]],
        ];

        try {
            $mpobTrendData = $this->buildMpobTrendData();
        } catch (\Throwable $exception) {
            report($exception);
        }

        $statusMills = Mill::query()
            ->whereIn('code', ['KHG', 'BBJ'])
            ->orderByRaw("CASE code WHEN 'KHG' THEN 1 WHEN 'BBJ' THEN 2 ELSE 99 END")
            ->get();

        $operationalStatusByMill = $statusMills->map(function ($mill) use ($displayDate) {
            $latestBalanceRecord = DailyOperation::where('mill_id', $mill->id)
                ->where('tarikh', '<=', $displayDate)
                ->whereNotNull('baki_bts_selepas_diproses')
                ->orderByDesc('tarikh')
                ->orderByDesc('id')
                ->first();
            $latestCpoStockRecord = DailyOperation::where('mill_id', $mill->id)
                ->where('tarikh', '<=', $displayDate)
                ->whereNotNull('stok_cpo')
                ->orderByDesc('tarikh')
                ->orderByDesc('id')
                ->first();

            return [
                'mill_id' => $mill->id,
                'code' => $mill->code,
                'name' => $mill->name,
                'source_tarikh' => $latestBalanceRecord?->tarikh?->toDateString(),
                'source_tarikh_text' => $latestBalanceRecord?->tarikh
                    ? $latestBalanceRecord->tarikh->translatedFormat('d F Y')
                    : '-',
                'baki_bts_selepas_diproses' => $latestBalanceRecord?->baki_bts_selepas_diproses,
                'stok_cpo' => $latestCpoStockRecord?->stok_cpo,
                'stok_cpo_tarikh_text' => $latestCpoStockRecord?->tarikh
                    ? $latestCpoStockRecord->tarikh->translatedFormat('d F Y')
                    : '-',
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
            $exists = DailyOperation::where('mill_id', $mill->id)->whereDate('tarikh', $displayDate)->exists();
            if (! $exists) {
                $millsBelumHantar->push($mill->name);
            }
        }

        $trendStart = $displayDateCarbon->copy()->startOfMonth()->toDateString();
        $trendEnd = $displayDate;
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
            $btsProcessedTrend[] = $rows->isEmpty() ? null : round($rows->sum('bts_diproses'), 2);
            $hasProcessedBts = (float) $rows->sum('bts_diproses') > 0;
            $oerTrend[] = ! $rows->isEmpty() && $hasProcessedBts
                ? round($this->computeRateFromRows($rows, 'produksi_cpo'), 2)
                : null;
            $kerTrend[] = ! $rows->isEmpty() && $hasProcessedBts
                ? round($this->computeRateFromRows($rows, 'produksi_pk'), 2)
                : null;
            $downtimeTrend[] = $this->kpiEvaluationService->calculateDowntimePercentageFromRows($rows);

            $cursor->addDay();
        }

        $currentYear = (int) $displayDateCarbon->year;
        $displayMonth = (int) $displayDateCarbon->month;

        $mtd = (clone $baseQuery)
            ->forMonth($currentYear, $displayMonth)
            ->whereDate('tarikh', '<=', $displayDate)
            ->get();

        $alertMessages = [];
        $btsProgressByMill = [];
        foreach ($mills as $mill) {
            $millTodayData = $todayData->where('mill_id', $mill->id);
            $millMtdData = $mtd->where('mill_id', $mill->id);

            $btsProgressByMill[$mill->id] = $this->kpiEvaluationService->evaluateBtsProgress(
                (float) $millMtdData->sum('bts_diterima'),
                $mill->id,
                $displayDate,
                $millMtdData->isNotEmpty()
            );

            if ($millTodayData->isEmpty()) {
                continue;
            }

            $processingRows = $millTodayData
                ->filter(fn ($row) => $row->operation_status === 'Operasi');

            if ($processingRows->isEmpty()) {
                continue;
            }

            $millName = "Kilang Sawit PPNJ {$mill->name}";
            $millOer = $this->computeRateFromRows($processingRows, 'produksi_cpo');
            $oerResult = $this->kpiEvaluationService->evaluate(
                'oer',
                $millOer,
                $mill->id,
                $currentYear,
                $displayMonth,
                true,
                $displayDate
            );

            if (($oerResult['status'] ?? 'grey') === 'red') {
                $alertMessages[] = "🔻 OER {$millName} hari ini (" . number_format($millOer, 2) . "%) berada pada atau di bawah ambang merah KPI (" . number_format((float) ($oerResult['red_threshold'] ?? 0), 2) . "%).";
            }

            $downtimeResult = $this->kpiEvaluationService->evaluateDowntimeFromRows(
                $processingRows,
                $mill->id,
                $currentYear,
                $displayMonth,
                true,
                $displayDate
            );

            if (($downtimeResult['status'] ?? 'grey') === 'red') {
                $alertMessages[] = "⏱️ Downtime {$millName} hari ini (" . number_format((float) ($downtimeResult['actual_percentage'] ?? 0), 2) . "%) berada pada atau melepasi ambang merah KPI (" . number_format((float) ($downtimeResult['red_threshold'] ?? 0), 2) . "%).";
            }

        }

        $btsProgress = $selectedMillId
           ? ($btsProgressByMill[(int) $selectedMillId] ?? $this->kpiEvaluationService->combineBtsProgress([], $displayDate))
            : $this->kpiEvaluationService->combineBtsProgress($btsProgressByMill, $displayDate);
        $btsProgressLabel = $selectedMillId
            ? (string) ($mills->firstWhere('id', (int) $selectedMillId)?->name ?? 'Kilang')
            : 'Gabungan Kilang';
        $alertMessages[] = $this->formatBtsProgressMessage($btsProgressLabel, $btsProgress, $displayDateCarbon);

        $ytdMillIds = $selectedMillId ? [(int) $selectedMillId] : $mills->pluck('id')->all();
        $ytd = $this->ytdPerformanceService->rows($displayDateCarbon, $ytdMillIds);
        $ytdPerformance = $this->ytdPerformanceService->metrics($ytd);

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

        $periodRows = match ($selectedPeriod) {
            'mtd' => $mtd,
            'ytd' => $ytd,
            default => $todayData,
        };
        $periodHasRows = $periodRows->isNotEmpty();
        $periodProcessedBts = (float) $periodRows->sum('bts_diproses');
        $periodOperatingHours = (float) $periodRows->sum('jam_operasi');
        $periodMetrics = [
            'bts_diterima' => $periodHasRows ? (float) $periodRows->sum('bts_diterima') : null,
            'bts_diproses' => $periodHasRows ? $periodProcessedBts : null,
            'throughput' => $periodOperatingHours > 0
                ? round($periodProcessedBts / $periodOperatingHours, 2)
                : null,
            'pengeluaran_cpo' => $periodHasRows ? (float) $periodRows->sum('produksi_cpo') : null,
            'jualan_cpo' => $periodHasRows ? (float) $periodRows->sum('pengeluaran_cpo') : null,
            'oer' => $periodProcessedBts > 0 ? $this->computeRateFromRows($periodRows, 'produksi_cpo') : null,
            'pengeluaran_pk' => $periodHasRows ? (float) $periodRows->sum('produksi_pk') : null,
            'jualan_pk' => $periodHasRows ? (float) $periodRows->sum('pengeluaran_pk') : null,
            'ker' => $periodProcessedBts > 0 ? $this->computeRateFromRows($periodRows, 'produksi_pk') : null,
        ];
        if ($selectedPeriod === 'ytd') {
            $periodMetrics = $ytdPerformance;
        }

        $comparisonMetrics = $this->buildComparisonMetrics($mills, $periodRows, $selectedMillId);
        if ($selectedPeriod === 'ytd') {
            foreach ($mills as $mill) {
                if (array_key_exists($mill->code, $comparisonMetrics['by_mill'])) {
                    $comparisonMetrics['by_mill'][$mill->code] = $this->ytdPerformanceService
                        ->metrics($ytd->where('mill_id', $mill->id));
                }
            }
            $comparisonMetrics['total'] = $ytdPerformance;
        }
        $statusMills = $selectedMillId
            ? $mills->where('id', (int) $selectedMillId)->values()
            : $mills;
        $kpiStatuses = $this->buildKpiStatuses(
            $statusMills,
            $periodRows,
            $selectedPeriod,
            $displayDateCarbon,
            $currentYear,
            $displayMonth,
            $comparisonMetrics['by_mill']
        );
        $kpiProgress = $this->dashboardKpiProgressService->build(
            $statusMills,
            $periodRows,
            $comparisonMetrics['total'],
            $selectedPeriod,
            $displayDateCarbon
        );

        return view('dashboard.index', [
            'mills' => $mills,
            'selectedMillId' => $selectedMillId,
            'selectedPeriod' => $selectedPeriod,
            'displayDateCarbon' => $displayDateCarbon,
            'isAllMillsSelected' => $isAllMillsSelected,
            'canViewComparisonCharts' => $canViewComparisonCharts,
            'latestOperationDateText' => $latestOperationDateText,
            'lastUpdatedText' => $lastUpdatedText,
            'operationalStatus' => $operationalStatus,
            'mpobPrices' => $mpobPrices,
            'mpobTrendData' => $mpobTrendData,
            'operationalStatusByMill' => $operationalStatusByMill,
            'summary' => $summary,
            'millsBelumHantar' => $millsBelumHantar,
            'labels' => $labels,
            'btsProcessedTrend' => $btsProcessedTrend,
            'oerTrend' => $oerTrend,
            'kerTrend' => $kerTrend,
            'downtimeTrend' => $downtimeTrend,
            'alertMessages' => $alertMessages,
            'qualityPendingCount' => $qualityPendingCount,
            'dailyMetrics' => $dailyMetrics,
            'mtdMetrics' => $mtdMetrics,
            'ytdMetrics' => $ytdMetrics,
            'showYtdBaselineNote' => $currentYear === 2026,
            'ytdNote' => $this->ytdPerformanceService->note($displayDateCarbon),
            'operatedDays' => $operatedDays,
            'referenceDays' => $referenceDays,
            'mtdCpo' => $mtd->sum('pengeluaran_cpo'),
            'mtdBts' => $mtdBtsDiproses,
            'ytdCpo' => $ytd->sum('pengeluaran_cpo'),
            'ytdBts' => $ytdBtsDiproses,
            'periodMetrics' => $periodMetrics,
            'comparisonMetrics' => $comparisonMetrics,
            'kpiStatuses' => $kpiStatuses,
            'kpiProgress' => $kpiProgress,
            'trendHasData' => $trendData->isNotEmpty(),
        ]);
    }

    public function weather(Request $request, DashboardWeatherService $weatherService): JsonResponse
    {
        $user = $request->user();
        $mills = $user->isMillScopedRole()
            ? Mill::query()->where('id', $user->mill_id)->get()
            : Mill::query()->whereIn('code', ['KHG', 'BBJ'])->get();

        if (! $user->isMillScopedRole() && $request->filled('mill_id')) {
            $mills = $mills->where('id', (int) $request->input('mill_id'));
        }

        $weather = [];
        foreach ($mills as $mill) {
            if (in_array($mill->code, ['KHG', 'BBJ'], true)) {
                $weather[$mill->code] = $weatherService->forMill($mill->code);
            }
        }

        return response()->json(['weather' => $weather]);
    }

    private function buildComparisonMetrics($mills, $rows, $selectedMillId): array
    {
        $byMill = [
            'KHG' => null,
            'BBJ' => null,
        ];

        foreach ($mills as $mill) {
            if (! array_key_exists($mill->code, $byMill)) {
                continue;
            }

            $millRows = $rows->where('mill_id', $mill->id);
            $processed = (float) $millRows->sum('bts_diproses');
            $operatingHours = (float) $millRows->sum('jam_operasi');
            $hasRows = $millRows->isNotEmpty();
            $capacity = match ($mill->code) {
                'KHG' => 60.0,
                'BBJ' => 30.0,
                default => 0.0,
            };
            $capacityHours = $operatingHours * $capacity;

            $byMill[$mill->code] = [
                'bts_diterima' => $hasRows ? (float) $millRows->sum('bts_diterima') : null,
                'bts_diproses' => $hasRows ? $processed : null,
                'throughput' => $operatingHours > 0 ? round($processed / $operatingHours, 2) : null,
                'pengeluaran_cpo' => $hasRows ? (float) $millRows->sum('produksi_cpo') : null,
                'jualan_cpo' => $hasRows ? (float) $millRows->sum('pengeluaran_cpo') : null,
                'oer' => $processed > 0 ? $this->computeRateFromRows($millRows, 'produksi_cpo') : null,
                'pengeluaran_pk' => $hasRows ? (float) $millRows->sum('produksi_pk') : null,
                'jualan_pk' => $hasRows ? (float) $millRows->sum('pengeluaran_pk') : null,
                'ker' => $processed > 0 ? $this->computeRateFromRows($millRows, 'produksi_pk') : null,
                'utilisation' => $capacityHours > 0
                    ? round(($processed / $capacityHours) * 100, 2)
                    : null,
            ];
        }

        $includedRows = $selectedMillId
            ? $rows->where('mill_id', (int) $selectedMillId)
            : $rows;
        $totalProcessed = (float) $includedRows->sum('bts_diproses');
        $totalOperatingHours = (float) $includedRows->sum('jam_operasi');
        $capacityHours = 0.0;
        foreach ($mills as $mill) {
            if ($selectedMillId && (int) $mill->id !== (int) $selectedMillId) {
                continue;
            }
            $hours = (float) $includedRows
                ->where('mill_id', $mill->id)
                ->sum('jam_operasi');
            $millCapacity = match ($mill->code) {
                'KHG' => 60.0,
                'BBJ' => 30.0,
                default => 0.0,
            };
            $capacityHours += $hours * $millCapacity;
        }

        $hasIncludedRows = $includedRows->isNotEmpty();
        $total = [
            'bts_diterima' => $hasIncludedRows ? (float) $includedRows->sum('bts_diterima') : null,
            'bts_diproses' => $hasIncludedRows ? $totalProcessed : null,
            'throughput' => $totalOperatingHours > 0 ? round($totalProcessed / $totalOperatingHours, 2) : null,
            'pengeluaran_cpo' => $hasIncludedRows ? (float) $includedRows->sum('produksi_cpo') : null,
            'jualan_cpo' => $hasIncludedRows ? (float) $includedRows->sum('pengeluaran_cpo') : null,
            'oer' => $totalProcessed > 0 ? $this->computeRateFromRows($includedRows, 'produksi_cpo') : null,
            'pengeluaran_pk' => $hasIncludedRows ? (float) $includedRows->sum('produksi_pk') : null,
            'jualan_pk' => $hasIncludedRows ? (float) $includedRows->sum('pengeluaran_pk') : null,
            'ker' => $totalProcessed > 0 ? $this->computeRateFromRows($includedRows, 'produksi_pk') : null,
            'utilisation' => $capacityHours > 0
                ? round(($totalProcessed / $capacityHours) * 100, 2)
                : null,
        ];

        return ['by_mill' => $byMill, 'total' => $total];
    }

    private function buildKpiStatuses(
        $mills,
        $periodRows,
        string $period,
        Carbon $asOf,
        int $year,
        int $month,
        array $metricsByMill
    ): array {
        $indicators = [
            'bts_diterima' => ['indicator' => 'bts_diterima_dan_diproses', 'flow' => true],
            'bts_diproses' => ['indicator' => 'bts_diterima_dan_diproses', 'flow' => true],
            'throughput' => ['indicator' => 'throughput', 'flow' => false],
            'pengeluaran_cpo' => ['indicator' => 'pengeluaran_cpo', 'flow' => true],
            'jualan_cpo' => ['indicator' => 'jualan_cpo', 'flow' => true],
            'oer' => ['indicator' => 'oer', 'flow' => false],
            'pengeluaran_pk' => ['indicator' => 'pengeluaran_pk', 'flow' => true],
            'jualan_pk' => ['indicator' => 'jualan_pk', 'flow' => true],
            'ker' => ['indicator' => 'ker', 'flow' => false],
        ];
        $statuses = [];
        foreach ($indicators as $key => $indicator) {
            $millLabels = [];
            $tones = [];
            foreach ($mills as $mill) {
                if (! in_array($mill->code, ['KHG', 'BBJ'], true)) {
                    continue;
                }

                if ($indicator['flow'] && $period === 'harian') {
                    $millLabels[] = $mill->code . ': Tiada Sasaran';
                    $tones[] = 'neutral';
                    continue;
                }

                $millRows = $periodRows->where('mill_id', $mill->id);
                $actual = $metricsByMill[$mill->code][$key] ?? null;
                $evaluation = $this->kpiEvaluationService->evaluate(
                    $indicator['indicator'],
                    $actual,
                    (int) $mill->id,
                    $year,
                    $month,
                    $millRows->isNotEmpty(),
                    $asOf->toDateString(),
                    cumulative: $indicator['flow'] && $period === 'ytd'
                );
                $tone = $evaluation['status'] ?? 'grey';
                $label = match ($tone) {
                    'green' => 'Capai Sasaran',
                    'yellow' => 'Perhatian',
                    'red' => 'Belum Capai Sasaran',
                    default => ($evaluation['status_label'] ?? '') === 'Tiada Data'
                        ? 'Perhatian (Tiada Data)'
                        : 'Tiada Sasaran',
                };
                if ($tone === 'grey') {
                    $tone = 'neutral';
                }
                $tones[] = $tone;
                $millLabels[] = $mill->code . ': ' . $label;
            }

            if ($tones === []) {
                $statuses[$key] = ['tone' => 'neutral', 'label' => implode(' · ', $millLabels) ?: 'Tiada Sasaran'];
                continue;
            }

            $priority = ['red' => 4, 'yellow' => 3, 'grey' => 2, 'green' => 1];
            $worstTone = collect($tones)
                ->sortByDesc(fn (string $tone) => $priority[$tone] ?? 2)
                ->first();
            $statuses[$key] = [
                'tone' => $worstTone,
                'label' => implode(' · ', $millLabels),
            ];
        }

        return $statuses;
    }

    public function downloadPdf(Request $request, DashboardPdfService $dashboardPdfService)
    {
        $displayDate = Carbon::yesterday();
        if ($request->filled('tarikh')) {
            try {
                $requestedDate = Carbon::createFromFormat('Y-m-d', (string) $request->input('tarikh'));
                if ($requestedDate->format('Y-m-d') === $request->input('tarikh')) {
                    $displayDate = $requestedDate;
                }
            } catch (\Throwable) {
                // Kekalkan tarikh paparan lalai untuk parameter lama atau tidak sah.
            }
        }

        $millCode = strtoupper((string) $request->input('mill', ''));
        if (! in_array($millCode, DashboardPdfService::ALLOWED_MILL_CODES, true)) {
            $millCode = null;
        }

        $report = $dashboardPdfService->generate($displayDate, $millCode);

        return $report['pdf']->download($report['filename']);
    }

    private function formatBtsProgressMessage(string $scopeLabel, array $progress, Carbon $displayDate): string
    {
        if (($progress['status'] ?? 'grey') === 'grey') {
            return "📦 Kemajuan KPI BTS {$scopeLabel}: Tidak Dinilai kerana sasaran atau data tidak mencukupi.";
        }

        $actual = (float) $progress['actual_bts_mtd'];
        $target = (float) $progress['prorated_target'];
        $achievement = (float) $progress['achievement_percentage'];
        $variance = (float) $progress['variance'];
        $statusLabel = (string) $progress['status_label'];
        $differenceText = $variance >= 0
            ? 'melebihi sasaran ' . number_format($variance, 2) . ' MT'
            : 'ketinggalan ' . number_format(abs($variance), 2) . ' MT';

        return "📦 Kemajuan KPI BTS {$scopeLabel}: "
            . number_format($actual, 2)
            . ' MT daripada sasaran setakat '
            . $displayDate->translatedFormat('j F Y')
            . ' sebanyak '
            . number_format($target, 2)
            . ' MT — pencapaian '
            . number_format($achievement, 2)
            . "%, {$differenceText}. Status: {$statusLabel}.";
    }

    private function buildPdfAttentionMessages($millSummaries): array
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
            if (($summary['status'] ?? 'Tiada Data') !== 'Operasi' && ($summary['status'] ?? 'Tiada Data') !== 'Tiada Data') {
                $messages[] = 'Kilang ' . $summary['name'] . ' tidak beroperasi pada tarikh data (' . $summary['status'] . ').';
            }
        }

        if (empty($messages)) {
            $messages[] = 'Tiada isu kritikal berdasarkan data harian semasa.';
        }

        return $messages;
    }

    private function resolvePdfOperationStatus(?DailyOperation $record): string
    {
        if (! $record) {
            return 'Tiada Data';
        }

        if (! empty($record->operation_status)) {
            return $record->operation_status;
        }

        if ((float) ($record->bts_diproses ?? 0) > 0 || (float) ($record->jam_operasi ?? 0) > 0) {
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
        $production = (float) $rows->sum($productionField);
        $btsDiproses = (float) $rows->sum('bts_diproses');

        return $this->computeRate($production, $btsDiproses);
    }

    private function buildMpobTrendData(): array
    {
        $result = [];

        foreach (['cpo', 'pk', 'cpko'] as $category) {
            $rows = MpobPriceHistory::query()
                ->select(['trade_date', 'price'])
                ->where('category', $category)
                ->where('price', '>', 0)
                ->whereNotNull('trade_date')
                ->orderByDesc('trade_date')
                ->limit(30)
                ->get()
                ->sortBy('trade_date')
                ->values();

            $labels = $rows
                ->map(fn ($row) => Carbon::parse($row->trade_date)->toDateString())
                ->all();

            $values = $rows
                ->map(fn ($row) => round((float) $row->price, 2))
                ->all();

            $deltaSymbol = '—';
            $deltaAmount = 0.0;

            if (count($values) >= 2) {
                $previous = (float) $values[count($values) - 2];
                $latest = (float) $values[count($values) - 1];
                $deltaAmount = round($latest - $previous, 2);
                if ($deltaAmount > 0) {
                    $deltaSymbol = '▲';
                } elseif ($deltaAmount < 0) {
                    $deltaSymbol = '▼';
                }
            }

            $result[$category] = [
                'labels' => $labels,
                'values' => $values,
                'delta' => [
                    'symbol' => $deltaSymbol,
                    'amount' => $deltaAmount,
                ],
            ];
        }

        return $result;
    }
}
