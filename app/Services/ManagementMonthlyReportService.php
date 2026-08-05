<?php

namespace App\Services;

use App\Models\DailyOperation;
use App\Models\Mill;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ManagementMonthlyReportService
{
    public function __construct(private readonly KpiEvaluationService $kpiEvaluationService)
    {
    }

    public function generate(User $user, int $year, int $month, ?int $requestedMillId = null): array
    {
        $selectedMillId = $user->isMillScopedRole() ? (int) $user->mill_id : $requestedMillId;
        $mills = Mill::query()
            ->where('is_active', true)
            ->when($selectedMillId, fn ($query) => $query->whereKey($selectedMillId))
            ->orderBy('name')
            ->get();

        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();
        $rows = DailyOperation::query()
            ->whereIn('mill_id', $mills->pluck('id'))
            ->forMonth($year, $month)
            ->orderBy('tarikh')
            ->orderBy('id')
            ->get();

        $millReports = $mills->map(function (Mill $mill) use ($rows, $year, $month, $monthEnd) {
            return $this->buildScopeReport(
                $rows->where('mill_id', $mill->id)->values(),
                $mill,
                $year,
                $month,
                $monthEnd
            );
        })->values();

        $overall = $this->buildOverallReport($rows, $millReports, $year, $month);
        $showMillComparison = $selectedMillId === null && $millReports->count() > 1;
        $comparison = $showMillComparison
            ? $millReports->map(fn (array $report) => [
                'mill_id' => $report['mill']['id'],
                'code' => $report['mill']['code'],
                'name' => $report['mill']['name'],
                'metrics' => $report['metrics'],
                'kpi' => $report['kpi'],
            ])->values()->all()
            : [];
        $highlights = $this->buildHighlights($millReports);
        $hasKpiTargets = $millReports->contains(
            fn (array $report) => collect($report['kpi'])->contains(
                fn (array $result, string $code) => $code === 'bts'
                    ? ($result['target'] ?? null) !== null
                    : ($result['green_threshold'] ?? null) !== null
                        && ($result['red_threshold'] ?? null) !== null
            )
        );

        return [
            'meta' => [
                'year' => $year,
                'month' => $month,
                'month_name' => $monthStart->translatedFormat('F'),
                'period_label' => $monthStart->translatedFormat('F Y'),
                'scope_type' => $selectedMillId ? 'single_mill' : 'all_mills',
                'scope_mill_id' => $selectedMillId,
                'scope_label' => $selectedMillId
                    ? ($mills->first()?->name ?? 'Kilang Tidak Ditemui')
                    : 'Semua Kilang',
                'title' => 'LAPORAN PRESTASI BULANAN PENGURUSAN',
                'period_start' => $monthStart->toDateString(),
                'period_end' => $monthEnd->toDateString(),
                'as_of_date' => $this->resolveAsOfDate($rows, $monthEnd),
            ],
            'overall' => $overall,
            'mills' => $millReports->all(),
            'comparison' => $comparison,
            'highlights' => $highlights,
            'flags' => [
                'showMillComparison' => $showMillComparison,
                'hasKpiTargets' => $hasKpiTargets,
                'hasOperationalIssues' => $millReports->contains(
                    fn (array $report) => ! empty($report['operationalIssues'])
                ),
                'hasProductionData' => (float) $overall['metrics']['pengeluaran_cpo'] > 0
                    || (float) $overall['metrics']['pengeluaran_pk'] > 0,
                'hasDowntimeData' => (float) $overall['metrics']['jam_downtime'] > 0,
            ],
        ];
    }

    private function buildScopeReport(
        Collection $rows,
        Mill $mill,
        int $year,
        int $month,
        Carbon $monthEnd
    ): array {
        $metrics = $this->calculateMetrics($rows);
        $kpi = $this->evaluateKpis($metrics, $rows, $mill->id, $year, $month, $monthEnd);

        return [
            'mill' => [
                'id' => $mill->id,
                'code' => $mill->code,
                'name' => $mill->name,
            ],
            'metrics' => $metrics,
            'executiveCards' => $this->buildExecutiveCards($metrics, $kpi),
            'kpi' => $kpi,
            'trend' => $this->buildDailyTrend($rows),
            'operationalSummary' => $this->buildOperationalSummary($rows, $metrics),
            'operationalIssues' => $this->buildOperationalIssues($rows),
            'productFlow' => $this->buildProductFlow($rows),
        ];
    }

    private function buildOverallReport(Collection $rows, Collection $millReports, int $year, int $month): array
    {
        $metrics = $this->calculateMetrics($rows);
        $productFlow = [
            'cpo' => [
                'opening_stock' => round((float) $millReports->sum('productFlow.cpo.opening_stock'), 2),
                'production' => round((float) $millReports->sum('productFlow.cpo.production'), 2),
                'sales' => round((float) $millReports->sum('productFlow.cpo.sales'), 2),
                'closing_stock' => round((float) $millReports->sum('productFlow.cpo.closing_stock'), 2),
            ],
            'pk' => [
                'opening_stock' => round((float) $millReports->sum('productFlow.pk.opening_stock'), 2),
                'production' => round((float) $millReports->sum('productFlow.pk.production'), 2),
                'sales' => round((float) $millReports->sum('productFlow.pk.sales'), 2),
                'closing_stock' => round((float) $millReports->sum('productFlow.pk.closing_stock'), 2),
            ],
        ];

        return [
            'metrics' => $metrics,
            'trend' => $this->buildDailyTrend($rows),
            'operationalSummary' => $this->buildOperationalSummary($rows, $metrics),
            'productFlow' => $productFlow,
            'period' => ['year' => $year, 'month' => $month],
        ];
    }

    private function calculateMetrics(Collection $rows): array
    {
        // jam_operasi ialah field rasmi "Jam Operasi Kilang" dan sumber jumlah jam proses MPS.
        $totalOperatingHours = (float) $rows->sum('jam_operasi');
        $downtimePercentage = $this->kpiEvaluationService->calculateDowntimePercentageFromRows($rows);
        $closingStock = $this->calculateClosingStock($rows);

        return [
            'bts_diterima' => round((float) $rows->sum('bts_diterima'), 2),
            'bts_diproses' => round((float) $rows->sum('bts_diproses'), 2),
            'pengeluaran_cpo' => round((float) $rows->sum('produksi_cpo'), 2),
            'pengeluaran_pk' => round((float) $rows->sum('produksi_pk'), 2),
            'jualan_cpo' => round((float) $rows->sum('pengeluaran_cpo'), 2),
            'jualan_pk' => round((float) $rows->sum('pengeluaran_pk'), 2),
            'stok_cpo' => $closingStock['cpo'],
            'stok_pk' => $closingStock['pk'],
            'jualan_cpo_vs_pengeluaran_cpo' => $this->kpiEvaluationService
                ->calculateSalesToProductionPercentageFromRows($rows, 'pengeluaran_cpo', 'produksi_cpo'),
            'jualan_pk_vs_pengeluaran_pk' => $this->kpiEvaluationService
                ->calculateSalesToProductionPercentageFromRows($rows, 'pengeluaran_pk', 'produksi_pk'),
            'oer' => $this->kpiEvaluationService->calculateProductionRateFromRows($rows, 'produksi_cpo'),
            'ker' => $this->kpiEvaluationService->calculateProductionRateFromRows($rows, 'produksi_pk'),
            'throughput' => $this->kpiEvaluationService->calculateThroughputFromRows($rows),
            'jam_proses' => round($totalOperatingHours, 2),
            'jam_downtime' => round((float) $rows->sum('downtime_jam'), 2),
            'downtime_percentage' => $downtimePercentage,
            'baki_bts_akhir' => $rows->isNotEmpty()
                ? round((float) $rows->sortBy([['tarikh', 'asc'], ['id', 'asc']])->last()->baki_bts_selepas_diproses, 2)
                : null,
        ];
    }

    private function evaluateKpis(
        array $metrics,
        Collection $rows,
        int $millId,
        int $year,
        int $month,
        Carbon $monthEnd
    ): array {
        $asOfDate = $this->resolveAsOfDate($rows, $monthEnd);
        $hasData = $asOfDate !== null;

        return [
            'bts' => $this->kpiEvaluationService->evaluateBtsCombined(
                $metrics['bts_diterima'],
                $metrics['bts_diproses'],
                $millId,
                $year,
                $month,
                $hasData,
                $asOfDate
            ),
            'pengeluaran_cpo' => $this->kpiEvaluationService->evaluate(
                'pengeluaran_cpo', $metrics['pengeluaran_cpo'], $millId, $year, $month, $hasData, $asOfDate
            ),
            'pengeluaran_pk' => $this->kpiEvaluationService->evaluate(
                'pengeluaran_pk', $metrics['pengeluaran_pk'], $millId, $year, $month, $hasData, $asOfDate
            ),
            'jualan_cpo' => $this->kpiEvaluationService->evaluate(
                'jualan_cpo', $metrics['jualan_cpo'], $millId, $year, $month, $hasData, $asOfDate
            ),
            'jualan_pk' => $this->kpiEvaluationService->evaluate(
                'jualan_pk', $metrics['jualan_pk'], $millId, $year, $month, $hasData, $asOfDate
            ),
            'stok_cpo' => $this->kpiEvaluationService->evaluate(
                'stok_cpo', $metrics['stok_cpo'], $millId, $year, $month, $hasData, $asOfDate
            ),
            'stok_pk' => $this->kpiEvaluationService->evaluate(
                'stok_pk', $metrics['stok_pk'], $millId, $year, $month, $hasData, $asOfDate
            ),
            'oer' => $this->kpiEvaluationService->evaluate(
                'oer', $metrics['oer'], $millId, $year, $month, $hasData, $asOfDate
            ),
            'ker' => $this->kpiEvaluationService->evaluate(
                'ker', $metrics['ker'], $millId, $year, $month, $hasData, $asOfDate
            ),
            'throughput' => $this->kpiEvaluationService->evaluate(
                'throughput', $metrics['throughput'], $millId, $year, $month, $hasData, $asOfDate
            ),
            'downtime' => $this->kpiEvaluationService->evaluateDowntimeFromRows(
                $rows, $millId, $year, $month, $hasData, $asOfDate
            ),
            'jualan_cpo_vs_pengeluaran_cpo' => $this->kpiEvaluationService->evaluate(
                'jualan_cpo_vs_pengeluaran_cpo',
                $metrics['jualan_cpo_vs_pengeluaran_cpo'],
                $millId,
                $year,
                $month,
                $hasData,
                $asOfDate
            ),
            'jualan_pk_vs_pengeluaran_pk' => $this->kpiEvaluationService->evaluate(
                'jualan_pk_vs_pengeluaran_pk',
                $metrics['jualan_pk_vs_pengeluaran_pk'],
                $millId,
                $year,
                $month,
                $hasData,
                $asOfDate
            ),
        ];
    }

    private function resolveAsOfDate(Collection $rows, Carbon $monthEnd): ?string
    {
        if ($rows->isEmpty()) {
            return null;
        }

        if ($monthEnd->isBefore(Carbon::today())) {
            return $monthEnd->toDateString();
        }

        return $rows
            ->filter(fn ($row) => $row->tarikh->lte(Carbon::today()))
            ->max('tarikh')
            ?->toDateString();
    }

    private function calculateClosingStock(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['cpo' => null, 'pk' => null];
        }

        $latestByMill = $rows
            ->groupBy('mill_id')
            ->map(fn (Collection $millRows) => $millRows->sortBy([['tarikh', 'asc'], ['id', 'asc']])->last());

        return [
            'cpo' => round((float) $latestByMill->sum('stok_cpo'), 2),
            'pk' => round((float) $latestByMill->sum('stok_pk'), 2),
        ];
    }

    private function buildExecutiveCards(array $metrics, array $kpi): array
    {
        return [
            ['code' => 'bts_diproses', 'label' => 'BTS Diproses', 'actual' => $metrics['bts_diproses'], 'unit' => 'MT', 'kpi' => $kpi['bts']],
            ['code' => 'pengeluaran_cpo', 'label' => 'Pengeluaran CPO', 'actual' => $metrics['pengeluaran_cpo'], 'unit' => 'MT', 'kpi' => $kpi['pengeluaran_cpo']],
            ['code' => 'oer', 'label' => 'OER', 'actual' => $metrics['oer'], 'unit' => '%', 'kpi' => $kpi['oer']],
            ['code' => 'ker', 'label' => 'KER', 'actual' => $metrics['ker'], 'unit' => '%', 'kpi' => $kpi['ker']],
            ['code' => 'throughput', 'label' => 'Throughput', 'actual' => $metrics['throughput'], 'unit' => 'MT/Jam', 'kpi' => $kpi['throughput']],
            ['code' => 'downtime', 'label' => 'Downtime', 'actual' => $metrics['downtime_percentage'], 'unit' => '%', 'kpi' => $kpi['downtime']],
        ];
    }

    private function buildDailyTrend(Collection $rows): array
    {
        return $rows
            ->groupBy(fn ($row) => $row->tarikh->toDateString())
            ->sortKeys()
            ->map(function (Collection $dayRows, string $date) {
                $btsDiproses = (float) $dayRows->sum('bts_diproses');

                return [
                    'date' => $date,
                    'bts_diterima' => round((float) $dayRows->sum('bts_diterima'), 2),
                    'bts_diproses' => round($btsDiproses, 2),
                    'cpo' => round((float) $dayRows->sum('produksi_cpo'), 2),
                    'pk' => round((float) $dayRows->sum('produksi_pk'), 2),
                    'oer' => $this->kpiEvaluationService->calculateProductionRateFromRows($dayRows, 'produksi_cpo'),
                    'ker' => $this->kpiEvaluationService->calculateProductionRateFromRows($dayRows, 'produksi_pk'),
                    'throughput' => $this->kpiEvaluationService->calculateThroughputFromRows($dayRows),
                    'downtime_percentage' => $this->kpiEvaluationService->calculateDowntimePercentageFromRows($dayRows),
                ];
            })
            ->values()
            ->all();
    }

    private function buildOperationalSummary(Collection $rows, array $metrics): array
    {
        $dailyRows = $rows->groupBy(fn ($row) => $row->tarikh->toDateString())->sortKeys();
        $operatedDates = $dailyRows->filter(fn (Collection $items) => $items->contains(
            fn ($row) => ($row->operation_status ?? null) === 'Operasi'
                || (($row->operation_status ?? null) === null
                    && ((float) $row->bts_diproses > 0 || (float) $row->jam_operasi > 0))
        ));
        $dailyStats = $this->buildDailyTrend($rows);
        $validOer = collect($dailyStats)->whereNotNull('oer')->filter(fn ($row) => $row['bts_diproses'] > 0);
        $validKer = collect($dailyStats)->whereNotNull('ker')->filter(fn ($row) => $row['bts_diproses'] > 0);
        $highestDowntime = $dailyRows->map(fn (Collection $items, string $date) => [
            'date' => $date,
            'hours' => round((float) $items->sum('downtime_jam'), 2),
        ])->sortByDesc('hours')->first();

        return [
            'record_days' => $dailyRows->count(),
            'operating_days' => $operatedDates->count(),
            'non_operating_days' => $dailyRows->count() - $operatedDates->count(),
            'process_hours' => $metrics['jam_proses'],
            'downtime_hours' => $metrics['jam_downtime'],
            'pooled_throughput' => $metrics['throughput'],
            'highest_downtime' => $highestDowntime,
            'highest_oer' => $this->extreme($validOer, 'oer', true),
            'lowest_oer' => $this->extreme($validOer, 'oer', false),
            'highest_ker' => $this->extreme($validKer, 'ker', true),
            'lowest_ker' => $this->extreme($validKer, 'ker', false),
        ];
    }

    private function buildProductFlow(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [
                'cpo' => ['opening_stock' => null, 'production' => 0.0, 'sales' => 0.0, 'closing_stock' => null],
                'pk' => ['opening_stock' => null, 'production' => 0.0, 'sales' => 0.0, 'closing_stock' => null],
            ];
        }

        $ordered = $rows->sortBy([['tarikh', 'asc'], ['id', 'asc']])->values();
        $first = $ordered->first();
        $last = $ordered->last();

        return [
            'cpo' => [
                'opening_stock' => round((float) $first->stok_cpo_yesterday, 2),
                'production' => round((float) $rows->sum('produksi_cpo'), 2),
                'sales' => round((float) $rows->sum('pengeluaran_cpo'), 2),
                'closing_stock' => round((float) $last->stok_cpo, 2),
            ],
            'pk' => [
                'opening_stock' => round((float) $first->stok_pk_yesterday, 2),
                'production' => round((float) $rows->sum('produksi_pk'), 2),
                'sales' => round((float) $rows->sum('pengeluaran_pk'), 2),
                'closing_stock' => round((float) $last->stok_pk, 2),
            ],
        ];
    }

    private function buildOperationalIssues(Collection $rows): array
    {
        return $rows
            ->filter(fn ($row) => filled($row->isu_operasi))
            ->map(fn ($row) => [
                'date' => $row->tarikh->toDateString(),
                'issue' => (string) $row->isu_operasi,
                'corrective_action' => filled($row->tindakan_pembetulan)
                    ? (string) $row->tindakan_pembetulan
                    : null,
            ])
            ->values()
            ->all();
    }

    private function buildHighlights(Collection $millReports): array
    {
        $achievements = [];
        $attentionItems = [];
        $operationalObservations = [];

        foreach ($millReports as $report) {
            $millName = $report['mill']['name'];
            foreach ($report['kpi'] as $code => $result) {
                $status = $result['status'] ?? 'grey';
                if ($status === 'green') {
                    $achievements[] = ['type' => 'kpi_achieved', 'mill' => $millName, 'indicator' => $code, 'status' => $status];
                } elseif (in_array($status, ['yellow', 'red'], true)) {
                    $attentionItems[] = ['type' => 'kpi_attention', 'mill' => $millName, 'indicator' => $code, 'status' => $status];
                }
            }

            $highestDowntime = $report['operationalSummary']['highest_downtime'];
            if ($highestDowntime && (float) $highestDowntime['hours'] > 0) {
                $operationalObservations[] = [
                    'type' => 'highest_downtime_date',
                    'mill' => $millName,
                    'date' => $highestDowntime['date'],
                    'value' => $highestDowntime['hours'],
                    'unit' => 'jam',
                ];
            }

            foreach ($report['operationalIssues'] as $issue) {
                $operationalObservations[] = [
                    'type' => 'recorded_operational_issue',
                    'mill' => $millName,
                    'date' => $issue['date'],
                    'issue' => $issue['issue'],
                    'corrective_action' => $issue['corrective_action'],
                ];
            }
        }

        return compact('achievements', 'attentionItems', 'operationalObservations');
    }

    private function extreme(Collection $rows, string $field, bool $highest): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $row = $highest ? $rows->sortByDesc($field)->first() : $rows->sortBy($field)->first();

        return ['date' => $row['date'], 'value' => $row[$field]];
    }
}
