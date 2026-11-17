<?php

namespace App\Services;

use App\Models\KpiIndicatorSetting;
use App\Models\Mill;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class DashboardKpiProgressService
{
    private const QUANTITY_INDICATORS = [
        'bts_diterima' => 'bts_diterima_dan_diproses',
        'bts_diproses' => 'bts_diterima_dan_diproses',
        'pengeluaran_cpo' => 'pengeluaran_cpo',
        'jualan_cpo' => 'jualan_cpo',
        'pengeluaran_pk' => 'pengeluaran_pk',
        'jualan_pk' => 'jualan_pk',
    ];

    private const RATIO_INDICATORS = [
        'throughput' => 'throughput',
        'oer' => 'oer',
        'ker' => 'ker',
    ];

    public function __construct(private readonly KpiEvaluationService $kpiEvaluationService)
    {
    }

    public function build(
        Collection $mills,
        Collection $rows,
        array $totalMetrics,
        string $period,
        CarbonInterface $asOf
    ): array {
        if ($period === 'harian') {
            return [];
        }

        $progress = [];
        foreach (self::QUANTITY_INDICATORS as $key => $indicatorCode) {
            $millTargets = $mills->mapWithKeys(fn (Mill $mill) => [
                (int) $mill->id => $this->quantityTarget($indicatorCode, (int) $mill->id, $period, $asOf),
            ]);
            $targets = $millTargets->pluck('target');
            $target = $targets->contains(null) || $targets->isEmpty()
                ? null
                : (float) $targets->sum();
            $estimated = $target !== null && $millTargets->contains(fn (array $item) => $item['estimated']);

            $progress[$key] = $this->result(
                $totalMetrics[$key] ?? null,
                $target,
                $estimated ? 'Sasaran YTD (Anggaran)' : ($period === 'ytd' ? 'Sasaran YTD' : 'Sasaran MTD')
            );
        }

        foreach (self::RATIO_INDICATORS as $key => $indicatorCode) {
            $millTargets = $mills->mapWithKeys(fn (Mill $mill) => [
                (int) $mill->id => [
                    'target' => $this->ratioTarget($indicatorCode, (int) $mill->id, $period, $asOf),
                ],
            ]);
            $target = $this->weightedRatioTarget($key, $mills, $rows, $millTargets);

            $progress[$key] = $this->result(
                $totalMetrics[$key] ?? null,
                $target,
                $period === 'ytd' ? 'Sasaran YTD' : 'Sasaran MTD'
            );
        }

        return $progress;
    }

    private function quantityTarget(
        string $indicatorCode,
        int $millId,
        string $period,
        CarbonInterface $asOf
    ): array {
        $month = (int) $asOf->month;

        if ($period === 'mtd') {
            if ($indicatorCode === 'bts_diterima_dan_diproses') {
                $btsProgress = $this->kpiEvaluationService->evaluateBtsProgress(
                    0.0,
                    $millId,
                    $asOf->toDateString(),
                    true
                );

                return ['target' => $btsProgress['prorated_target'], 'estimated' => false];
            }

            $setting = $this->kpiEvaluationService->resolveSetting(
                $indicatorCode,
                $millId,
                (int) $asOf->year
            );
            $thresholds = $setting
                ? $this->kpiEvaluationService->resolveMonthlyThresholds($setting, $month)
                : [];
            $monthlyTarget = $this->positiveTarget($thresholds['green'] ?? null);

            return ['target' => $monthlyTarget !== null
                ? $monthlyTarget * (int) $asOf->day / (int) $asOf->daysInMonth
                : null, 'estimated' => false];
        }

        $setting = $this->kpiEvaluationService->resolveSetting($indicatorCode, $millId, (int) $asOf->year);
        $monthlyTargets = $this->monthlyGreenTargets($setting);

        if ((int) $asOf->year === 2026) {
            $secondHalfTargets = array_filter(
                array_intersect_key($monthlyTargets, array_flip(range(7, 12))),
                fn (float $target): bool => $target > 0
            );
            if ($secondHalfTargets === []) {
                return ['target' => null, 'estimated' => false];
            }

            $averageTarget = array_sum($secondHalfTargets) / count($secondHalfTargets);
            $yearToDateTarget = 0.0;
            for ($targetMonth = 1; $targetMonth <= $month; $targetMonth++) {
                $monthTarget = $targetMonth <= 6
                    ? $averageTarget
                    : ($monthlyTargets[$targetMonth] ?? $averageTarget);
                $yearToDateTarget += $targetMonth === $month
                    ? $monthTarget * (int) $asOf->day / (int) $asOf->daysInMonth
                    : $monthTarget;
            }

            return ['target' => $yearToDateTarget, 'estimated' => true];
        }

        $yearToDateTarget = 0.0;
        for ($targetMonth = 1; $targetMonth <= $month; $targetMonth++) {
            $monthTarget = $monthlyTargets[$targetMonth] ?? null;
            if ($monthTarget === null || $monthTarget <= 0) {
                return ['target' => null, 'estimated' => false];
            }
            $yearToDateTarget += $targetMonth === $month
                ? $monthTarget * (int) $asOf->day / (int) $asOf->daysInMonth
                : $monthTarget;
        }

        return ['target' => $yearToDateTarget, 'estimated' => false];
    }

    private function ratioTarget(string $indicatorCode, int $millId, string $period, CarbonInterface $asOf): ?float
    {
        $setting = $this->kpiEvaluationService->resolveSetting($indicatorCode, $millId, (int) $asOf->year);
        if (! $setting) {
            return null;
        }

        if ($period === 'mtd') {
            $monthlyTarget = $this->monthlyGreenTargets($setting)[(int) $asOf->month] ?? null;

            return $this->positiveTarget($monthlyTarget) ?? $this->positiveTarget($setting->green_threshold);
        }

        if ($this->positiveTarget($setting->period_target) !== null) {
            return (float) $setting->period_target;
        }

        $monthlyTargets = $this->monthlyGreenTargets($setting);
        $secondHalfTargets = array_filter(
            array_intersect_key($monthlyTargets, array_flip(range(7, 12))),
            fn (float $target): bool => $target > 0
        );
        if ($secondHalfTargets !== []) {
            return array_sum($secondHalfTargets) / count($secondHalfTargets);
        }

        return $this->positiveTarget($setting->green_threshold);
    }

    private function weightedRatioTarget(
        string $key,
        Collection $mills,
        Collection $rows,
        Collection $millTargets
    ): ?float {
        if ($mills->count() === 1) {
            return $millTargets->first()['target'] ?? null;
        }

        $weightField = $key === 'throughput' ? 'jam_operasi' : 'bts_diproses';
        $weightedTarget = 0.0;
        $totalWeight = 0.0;
        foreach ($mills as $mill) {
            $weight = (float) $rows->where('mill_id', $mill->id)->sum($weightField);
            if ($weight <= 0) {
                continue;
            }

            $target = $millTargets->get((int) $mill->id)['target'] ?? null;
            if ($target === null) {
                return null;
            }

            // Pool ratio benchmarks by the same denominator used in the actual: BTS for OER/KER, hours for throughput.
            $weightedTarget += $target * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? $weightedTarget / $totalWeight : null;
    }

    private function monthlyGreenTargets(?KpiIndicatorSetting $setting): array
    {
        if (! $setting || ! $setting->is_active) {
            return [];
        }

        $targets = [];
        foreach ($setting->monthly_targets ?? [] as $month => $configuration) {
            if (! is_numeric($month) || (int) $month < 1 || (int) $month > 12) {
                continue;
            }

            $target = is_array($configuration)
                ? ($configuration['green'] ?? null)
                : $configuration;
            $target = $this->positiveTarget($target);
            if ($target !== null) {
                $targets[(int) $month] = $target;
            }
        }

        return $targets;
    }

    private function positiveTarget(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function result(?float $actual, ?float $target, string $targetLabel): array
    {
        if ($target === null || $target <= 0) {
            return [
                'target' => null,
                'target_label' => 'Tiada Sasaran',
                'achievement_percentage' => null,
                'bar_percentage' => 0,
                'remaining_percentage' => null,
                'excess_percentage' => null,
                'remaining_amount' => null,
                'tone' => 'neutral',
                'status_label' => 'Tiada Sasaran',
            ];
        }

        $achievement = $actual !== null ? (float) $actual / $target * 100 : null;
        if ($achievement === null) {
            return [
                'target' => round($target, 2),
                'target_label' => $targetLabel,
                'achievement_percentage' => null,
                'bar_percentage' => 0,
                'remaining_percentage' => null,
                'excess_percentage' => null,
                'remaining_amount' => null,
                'tone' => 'neutral',
                'status_label' => null,
            ];
        }

        $tone = $achievement >= 100 ? 'green' : ($achievement >= 90 ? 'yellow' : 'red');

        return [
            'target' => round($target, 2),
            'target_label' => $targetLabel,
            'achievement_percentage' => round($achievement, 2),
            'bar_percentage' => min(100, max(0, $achievement)),
            'remaining_percentage' => round(max(0, 100 - $achievement), 2),
            'excess_percentage' => round(max(0, $achievement - 100), 2),
            'remaining_amount' => round(max(0, $target - (float) $actual), 2),
            'tone' => $tone,
            'status_label' => match ($tone) {
                'green' => 'Capai Sasaran',
                'yellow' => 'Hampir Capai',
                default => 'Belum Capai',
            },
        ];
    }
}
