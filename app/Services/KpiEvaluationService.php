<?php

namespace App\Services;

use App\Models\KpiIndicatorSetting;
use Carbon\Carbon;

class KpiEvaluationService
{
    private const STATUS_PRIORITY = [
        'red' => 4,
        'yellow' => 3,
        'grey' => 2,
        'green' => 1,
    ];

    private const NOTE_2026 = 'Pengiraan YTD tahun 2026 adalah berdasarkan data MPS bermula 1 Julai 2026.';

    /**
     * Katalog KPI fasa 2 (FFA legacy tidak dipaparkan dalam ringkasan baharu).
     */
    public static function indicatorCatalog(): array
    {
        return [
            [
                'code' => 'bts_diterima_dan_diproses',
                'name' => 'Penerimaan & Pemprosesan BTS',
                'unit' => 'MT',
                'direction' => 'higher_is_better',
                'evaluation_basis' => 'monthly_flow',
                'section' => 'Penerimaan & Pemprosesan BTS',
                'supports_monthly_target' => true,
            ],
            [
                'code' => 'oer',
                'name' => 'OER',
                'unit' => '%',
                'direction' => 'higher_is_better',
                'evaluation_basis' => 'direct_value',
                'section' => 'Prestasi Pengeluaran',
                'supports_monthly_target' => false,
            ],
            [
                'code' => 'ker',
                'name' => 'KER',
                'unit' => '%',
                'direction' => 'higher_is_better',
                'evaluation_basis' => 'direct_value',
                'section' => 'Prestasi Pengeluaran',
                'supports_monthly_target' => false,
            ],
            [
                'code' => 'throughput',
                'name' => 'Throughput',
                'unit' => 'MT/Jam',
                'direction' => 'higher_is_better',
                'evaluation_basis' => 'direct_value',
                'section' => 'Prestasi Pengeluaran',
                'supports_monthly_target' => false,
            ],
            [
                'code' => 'pengeluaran_cpo',
                'name' => 'Pengeluaran CPO',
                'unit' => 'MT',
                'direction' => 'higher_is_better',
                'evaluation_basis' => 'monthly_flow',
                'section' => 'Prestasi Pengeluaran',
                'supports_monthly_target' => true,
            ],
            [
                'code' => 'pengeluaran_pk',
                'name' => 'Pengeluaran PK',
                'unit' => 'MT',
                'direction' => 'higher_is_better',
                'evaluation_basis' => 'monthly_flow',
                'section' => 'Prestasi Pengeluaran',
                'supports_monthly_target' => true,
            ],
            [
                'code' => 'jualan_cpo',
                'name' => 'Jualan CPO',
                'unit' => 'MT',
                'direction' => 'higher_is_better',
                'evaluation_basis' => 'monthly_flow',
                'section' => 'Prestasi Pengeluaran',
                'supports_monthly_target' => true,
            ],
            [
                'code' => 'jualan_pk',
                'name' => 'Jualan PK',
                'unit' => 'MT',
                'direction' => 'higher_is_better',
                'evaluation_basis' => 'monthly_flow',
                'section' => 'Prestasi Pengeluaran',
                'supports_monthly_target' => true,
            ],
            [
                'code' => 'stok_cpo',
                'name' => 'Stok CPO',
                'unit' => 'MT',
                'direction' => 'lower_is_better',
                'evaluation_basis' => 'direct_value',
                'section' => 'Stok dan Downtime',
                'supports_monthly_target' => false,
            ],
            [
                'code' => 'stok_pk',
                'name' => 'Stok PK',
                'unit' => 'MT',
                'direction' => 'lower_is_better',
                'evaluation_basis' => 'direct_value',
                'section' => 'Stok dan Downtime',
                'supports_monthly_target' => false,
            ],
            [
                'code' => 'downtime',
                'name' => 'Downtime (%)',
                'unit' => '%',
                'direction' => 'lower_is_better',
                'evaluation_basis' => 'direct_value',
                'section' => 'Stok dan Downtime',
                'supports_monthly_target' => false,
            ],
            [
                'code' => 'jualan_cpo_vs_pengeluaran_cpo',
                'name' => 'Jualan CPO berbanding Pengeluaran CPO',
                'unit' => '%',
                'direction' => 'higher_is_better',
                'evaluation_basis' => 'direct_value',
                'section' => 'Jualan Berbanding Pengeluaran',
                'supports_monthly_target' => false,
            ],
            [
                'code' => 'jualan_pk_vs_pengeluaran_pk',
                'name' => 'Jualan PK berbanding Pengeluaran PK',
                'unit' => '%',
                'direction' => 'higher_is_better',
                'evaluation_basis' => 'direct_value',
                'section' => 'Jualan Berbanding Pengeluaran',
                'supports_monthly_target' => false,
            ],
        ];
    }

    public static function indicatorMap(): array
    {
        $map = [];
        foreach (self::indicatorCatalog() as $indicator) {
            $map[$indicator['code']] = $indicator;
        }

        return $map;
    }

    public function resolveSetting(string $indicatorCode, ?int $millId, int $year): ?KpiIndicatorSetting
    {
        if ($millId === null || $millId <= 0) {
            return null;
        }

        $setting = KpiIndicatorSetting::query()
            ->where('indicator_code', $indicatorCode)
            ->where('year', $year)
            ->where('is_active', true)
            ->where('mill_id', $millId)
            ->latest('id')
            ->first();

        if ($setting || $indicatorCode !== 'bts_diterima_dan_diproses') {
            return $setting;
        }

        $newSettingExists = KpiIndicatorSetting::query()
            ->where('indicator_code', $indicatorCode)
            ->where('year', $year)
            ->where('mill_id', $millId)
            ->exists();

        if ($newSettingExists) {
            return null;
        }

        return KpiIndicatorSetting::query()
            ->where('indicator_code', 'total_bts_diterima')
            ->where('year', $year)
            ->where('is_active', true)
            ->where('mill_id', $millId)
            ->latest('id')
            ->first();
    }

    public function getApplicableMonths(int $year): array
    {
        return $year === 2026 ? range(7, 12) : range(1, 12);
    }

    public function getPeriodBounds(int $year): array
    {
        if ($year === 2026) {
            return [
                'start_date' => '2026-07-01',
                'end_date' => '2026-12-31',
                'ytd_start_date' => '2026-07-01',
                'period_label' => 'Sasaran Tempoh Julai-Disember 2026',
                'note' => self::NOTE_2026,
            ];
        }

        return [
            'start_date' => sprintf('%d-01-01', $year),
            'end_date' => sprintf('%d-12-31', $year),
            'ytd_start_date' => sprintf('%d-01-01', $year),
            'period_label' => sprintf('Sasaran Tahunan %d', $year),
            'note' => null,
        ];
    }

    public function resolveMonthlyThresholds(KpiIndicatorSetting $setting, int $month): array
    {
        $applicableMonths = $this->getApplicableMonths((int) $setting->year);
        if (! in_array($month, $applicableMonths, true)) {
            return [
                'green' => null,
                'red' => null,
                'target_type' => 'not_applicable',
                'target_label' => 'Tidak Berkenaan',
            ];
        }

        $monthlyTargets = $setting->monthly_targets ?? [];
        $monthConfig = $monthlyTargets[(string) $month] ?? $monthlyTargets[$month] ?? null;

        // Sokong format lama (numeric sahaja) tanpa rosakkan data sedia ada.
        if (is_numeric($monthConfig)) {
            return [
                'green' => (float) $monthConfig,
                'red' => null,
                'target_type' => 'configured',
                'target_label' => 'Sasaran Bulanan',
            ];
        }

        if (is_array($monthConfig)) {
            $green = $monthConfig['green'] ?? null;
            $red = $monthConfig['red'] ?? null;

            return [
                'green' => is_numeric($green) ? (float) $green : null,
                'red' => is_numeric($red) ? (float) $red : null,
                'target_type' => 'configured',
                'target_label' => 'Sasaran Bulanan',
            ];
        }

        return [
            'green' => null,
            'red' => null,
            'target_type' => 'none',
            'target_label' => 'Belum Ditetapkan',
        ];
    }

    public function calculateDowntimePercentage(float $downtimeHours, float $operatingHours): ?float
    {
        if ($operatingHours <= 0) {
            return null;
        }

        return round(($downtimeHours / $operatingHours) * 100, 2);
    }

    public function calculateProductionRateFromRows(iterable $rows, string $productionField): ?float
    {
        $totalProduction = 0.0;
        $totalProcessedBts = 0.0;

        foreach ($rows as $row) {
            $processedBts = (float) ($row->bts_diproses ?? 0);
            if ($processedBts <= 0) {
                continue;
            }

            $totalProduction += (float) ($row->{$productionField} ?? 0);
            $totalProcessedBts += $processedBts;
        }

        return $totalProcessedBts > 0
            ? round(($totalProduction / $totalProcessedBts) * 100, 2)
            : null;
    }

    public function calculateSalesToProductionPercentageFromRows(
        iterable $rows,
        string $salesField,
        string $productionField
    ): ?float {
        $totalSales = 0.0;
        $totalProduction = 0.0;

        foreach ($rows as $row) {
            $totalSales += (float) ($row->{$salesField} ?? 0);
            $totalProduction += (float) ($row->{$productionField} ?? 0);
        }

        return $totalProduction > 0
            ? round(($totalSales / $totalProduction) * 100, 2)
            : null;
    }

    public function calculateThroughputFromRows(iterable $rows): ?float
    {
        $totalProcessedBts = 0.0;
        $totalOperatingHours = 0.0;

        foreach ($rows as $row) {
            $totalProcessedBts += (float) ($row->bts_diproses ?? 0);
            $totalOperatingHours += (float) ($row->jam_operasi ?? 0);
        }

        return $totalOperatingHours > 0
            ? round($totalProcessedBts / $totalOperatingHours, 2)
            : null;
    }

    public function calculateDowntimePercentageFromRows(iterable $rows): ?float
    {
        $totalDowntimeHours = 0.0;
        $totalOperatingHours = 0.0;

        foreach ($rows as $row) {
            $totalDowntimeHours += (float) ($row->downtime_jam ?? 0);
            $totalOperatingHours += (float) ($row->jam_operasi ?? 0);
        }

        return $this->calculateDowntimePercentage($totalDowntimeHours, $totalOperatingHours);
    }

    public function evaluateDowntimeFromHours(
        float $downtimeHours,
        float $operatingHours,
        ?int $millId,
        int $year,
        ?int $month = null,
        ?bool $hasOperationalData = null,
        ?string $asOfDate = null
    ): array {
        $downtimePercentage = $this->calculateDowntimePercentage($downtimeHours, $operatingHours);

        if ($downtimePercentage === null) {
            $indicator = self::indicatorMap()['downtime'] ?? [
                'code' => 'downtime',
                'name' => 'Downtime (%)',
                'unit' => '%',
                'evaluation_basis' => 'direct_value',
            ];

            return $this->buildGreyResult(
                $indicator,
                null,
                'Tidak Berkenaan',
                'Jumlah jam proses adalah sifar. Downtime tidak boleh dinilai.',
                null,
                [
                    'actual' => null,
                    'actual_percentage' => null,
                    'actual_downtime_hours' => round($downtimeHours, 2),
                    'actual_operating_hours' => round($operatingHours, 2),
                    'variance' => null,
                ]
            );
        }

        $result = $this->evaluate(
            'downtime',
            $downtimePercentage,
            $millId,
            $year,
            $month,
            $hasOperationalData,
            $asOfDate
        );

        return array_merge($result, [
            'actual' => $downtimePercentage,
            'actual_percentage' => $downtimePercentage,
            'actual_downtime_hours' => round($downtimeHours, 2),
            'actual_operating_hours' => round($operatingHours, 2),
            'variance' => isset($result['green_threshold'])
                ? round($downtimePercentage - (float) $result['green_threshold'], 2)
                : null,
        ]);
    }

    public function evaluateDowntimeFromRows(
        iterable $rows,
        ?int $millId,
        int $year,
        ?int $month = null,
        ?bool $hasOperationalData = null,
        ?string $asOfDate = null
    ): array {
        $totalDowntimeHours = 0.0;
        $totalOperatingHours = 0.0;
        $rowCount = 0;

        foreach ($rows as $row) {
            $totalDowntimeHours += (float) ($row->downtime_jam ?? 0);
            $totalOperatingHours += (float) ($row->jam_operasi ?? 0);
            $rowCount++;
        }

        return $this->evaluateDowntimeFromHours(
            $totalDowntimeHours,
            $totalOperatingHours,
            $millId,
            $year,
            $month,
            $hasOperationalData ?? ($rowCount > 0),
            $asOfDate
        );
    }

    public function evaluateBtsCombined(
        ?float $actualBtsDiterima,
        ?float $actualBtsDiproses,
        ?int $millId,
        int $year,
        ?int $month = null,
        ?bool $hasOperationalData = null,
        ?string $asOfDate = null
    ): array {
        $diterimaResult = $this->evaluate(
            'bts_diterima_dan_diproses',
            $actualBtsDiterima,
            $millId,
            $year,
            $month,
            $hasOperationalData,
            $asOfDate
        );

        $diprosesResult = $this->evaluate(
            'bts_diterima_dan_diproses',
            $actualBtsDiproses,
            $millId,
            $year,
            $month,
            $hasOperationalData,
            $asOfDate
        );

        $overallResult = $this->resolveWeakestResult([$diterimaResult, $diprosesResult]);
        $target = $overallResult['green_threshold'] ?? null;

        return [
            'indicator_code' => 'bts_diterima_dan_diproses',
            'indicator_name' => 'Penerimaan & Pemprosesan BTS',
            'unit' => 'MT',
            'actual_bts_diterima' => $actualBtsDiterima,
            'actual_bts_diproses' => $actualBtsDiproses,
            'target' => $target,
            'status' => $overallResult['status'],
            'status_label' => $overallResult['status_label'],
            'colour' => $overallResult['colour'],
            'target_source' => $overallResult['target_source'] ?? 'none',
            'target_type' => $overallResult['target_type'] ?? 'none',
            'target_label' => $overallResult['target_label'] ?? 'Belum Ditetapkan',
            'target_value' => $overallResult['target_value'] ?? null,
            'evaluation_basis' => $overallResult['evaluation_basis'] ?? 'monthly_flow',
            'expected_target_to_date' => $overallResult['expected_target_to_date'] ?? null,
            'variance_bts_diterima' => $target !== null && $actualBtsDiterima !== null
                ? round($actualBtsDiterima - (float) $target, 2)
                : null,
            'variance_bts_diproses' => $target !== null && $actualBtsDiproses !== null
                ? round($actualBtsDiproses - (float) $target, 2)
                : null,
            'received_result' => $diterimaResult,
            'processed_result' => $diprosesResult,
        ];
    }

    public function evaluateBtsProgress(
        ?float $actualBtsMtd,
        ?int $millId,
        string $asOfDate,
        bool $hasOperationalData = true
    ): array {
        try {
            $date = Carbon::parse($asOfDate)->startOfDay();
        } catch (\Throwable) {
            return $this->buildBtsProgressResult(0, null, null);
        }

        $actual = max(0, (float) ($actualBtsMtd ?? 0));
        if (! $hasOperationalData || $actualBtsMtd === null) {
            return $this->buildBtsProgressResult($actual, null, $date);
        }

        $setting = $this->resolveSetting(
            'bts_diterima_dan_diproses',
            $millId,
            (int) $date->year
        );
        if (! $setting) {
            return $this->buildBtsProgressResult($actual, null, $date);
        }

        $thresholds = $this->resolveMonthlyThresholds($setting, (int) $date->month);
        $monthlyTarget = $thresholds['green'] ?? null;
        if (! is_numeric($monthlyTarget) || (float) $monthlyTarget <= 0) {
            return $this->buildBtsProgressResult($actual, null, $date);
        }

        $proratedTarget = (float) $monthlyTarget * $date->day / $date->daysInMonth;

        return $this->buildBtsProgressResult($actual, $proratedTarget, $date, (float) $monthlyTarget);
    }

    public function combineBtsProgress(iterable $results, string $asOfDate): array
    {
        try {
            $date = Carbon::parse($asOfDate)->startOfDay();
        } catch (\Throwable) {
            return $this->buildBtsProgressResult(0, null, null);
        }

        $actual = 0.0;
        $proratedTarget = 0.0;
        $monthlyTarget = 0.0;
        $validTargetCount = 0;

        foreach ($results as $result) {
            if (! is_numeric($result['prorated_target'] ?? null) || (float) $result['prorated_target'] <= 0) {
                continue;
            }

            $actual += max(0, (float) ($result['actual_bts_mtd'] ?? 0));
            $proratedTarget += (float) $result['prorated_target'];
            $monthlyTarget += max(0, (float) ($result['monthly_target'] ?? 0));
            $validTargetCount++;
        }

        if ($validTargetCount === 0) {
            return $this->buildBtsProgressResult(0, null, $date);
        }

        return $this->buildBtsProgressResult($actual, $proratedTarget, $date, $monthlyTarget);
    }

    public function evaluate(
        string $indicatorCode,
        ?float $actual,
        ?int $millId,
        int $year,
        ?int $month = null,
        ?bool $hasOperationalData = null,
        ?string $asOfDate = null
    ): array {
        $indicator = self::indicatorMap()[$indicatorCode] ?? null;
        if ($indicator === null) {
            return [
                'indicator_code' => $indicatorCode,
                'indicator_name' => $indicatorCode,
                'actual' => $actual,
                'unit' => null,
                'green_threshold' => null,
                'red_threshold' => null,
                'status' => 'grey',
                'status_label' => 'Belum Ditetapkan',
                'colour' => '#9CA3AF',
                'target_source' => 'none',
                'target_type' => 'none',
                'evaluation_basis' => null,
                'achievement_percentage' => null,
                'expected_target_to_date' => null,
                'message' => 'Kod indikator tidak dikenali.',
                'explanation' => 'Indikator belum didaftarkan dalam katalog KPI.',
            ];
        }

        $setting = $this->resolveSetting($indicatorCode, $millId, $year);
        if (! $setting) {
            return $this->buildGreyResult($indicator, $actual, 'Belum Ditetapkan', 'Tetapan KPI aktif tidak ditemui untuk indikator ini.');
        }

        if ($hasOperationalData === false || $actual === null) {
            return $this->buildGreyResult(
                $indicator,
                $actual,
                'Tiada Data',
                'Data operasi bagi indikator ini belum tersedia untuk tempoh dipilih.',
                $setting
            );
        }

        $targetSource = 'mill';
        $targetType = 'none';
        $targetLabel = 'Belum Ditetapkan';
        $targetValue = null;
        $expectedTargetToDate = null;
        $achievementPercentage = null;
        $green = null;
        $red = null;

        if ($indicator['evaluation_basis'] === 'monthly_flow') {
            if ($month === null) {
                return $this->buildGreyResult(
                    $indicator,
                    $actual,
                    'Belum Ditetapkan',
                    'Bulan penilaian diperlukan untuk indikator aliran bulanan.',
                    $setting,
                    [
                        'target_source' => $targetSource,
                        'target_type' => 'none',
                        'target_label' => 'Belum Ditetapkan',
                        'evaluation_basis' => $indicator['evaluation_basis'],
                    ]
                );
            }

            $monthlyThresholds = $this->resolveMonthlyThresholds($setting, $month);
            $targetType = $monthlyThresholds['target_type'];
            $targetLabel = $monthlyThresholds['target_label'];

            if ($targetType === 'not_applicable') {
                return $this->buildGreyResult(
                    $indicator,
                    $actual,
                    'Tidak Berkenaan',
                    'Bulan ini tidak termasuk dalam tempoh KPI yang berkenaan.',
                    $setting,
                    [
                        'target_source' => $targetSource,
                        'target_type' => $targetType,
                        'target_label' => $targetLabel,
                        'evaluation_basis' => $indicator['evaluation_basis'],
                    ]
                );
            }

            $green = $monthlyThresholds['green'];
            $red = $monthlyThresholds['red'];

            if ($green === null || $red === null) {
                return $this->buildGreyResult(
                    $indicator,
                    $actual,
                    'Belum Ditetapkan',
                    'Ambang bulanan hijau/merah belum lengkap.',
                    $setting,
                    [
                        'target_source' => $targetSource,
                        'target_type' => $targetType,
                        'target_label' => $targetLabel,
                        'evaluation_basis' => $indicator['evaluation_basis'],
                    ]
                );
            }

            if ($asOfDate !== null) {
                $prorated = $this->prorateThresholdsToDate($green, $red, $year, $month, $asOfDate);
                if ($prorated === null) {
                    return $this->buildGreyResult(
                        $indicator,
                        $actual,
                        'Belum Ditetapkan',
                        'Tarikh penilaian tidak sah untuk bulan KPI dipilih.',
                        $setting,
                        [
                            'target_source' => $targetSource,
                            'target_type' => $targetType,
                            'target_label' => $targetLabel,
                            'evaluation_basis' => $indicator['evaluation_basis'],
                        ]
                    );
                }

                $green = $prorated['green'];
                $red = $prorated['red'];
                $expectedTargetToDate = $green;
            } else {
                $expectedTargetToDate = $green;
            }

            $targetValue = [
                'green' => $green,
                'red' => $red,
            ];

            if ($green > 0) {
                $achievementPercentage = round((((float) $actual) / $green) * 100, 2);
            }
        } else {
            $green = $setting->green_threshold;
            $red = $setting->red_threshold;

            if ($green === null || $red === null) {
                return $this->buildGreyResult(
                    $indicator,
                    $actual,
                    'Belum Ditetapkan',
                    'Ambang hijau/merah belum lengkap.',
                    $setting,
                    [
                        'target_source' => $targetSource,
                        'evaluation_basis' => $indicator['evaluation_basis'],
                    ]
                );
            }

            $targetType = 'configured';
            $targetLabel = 'Ambang Tetapan';
            $targetValue = [
                'green' => (float) $green,
                'red' => (float) $red,
            ];
        }

        $isOrderValid = $setting->evaluation_direction === 'higher_is_better'
            ? $green >= $red
            : $green <= $red;

        if (! $isOrderValid) {
            return $this->buildGreyResult(
                $indicator,
                $actual,
                'Belum Ditetapkan',
                'Susunan ambang tidak sah untuk arah penilaian yang dipilih.',
                $setting,
                [
                    'target_source' => $targetSource,
                    'target_type' => $targetType,
                    'target_label' => $targetLabel,
                    'target_value' => $targetValue,
                    'evaluation_basis' => $indicator['evaluation_basis'],
                ]
            );
        }

        $status = 'yellow';
        $statusLabel = 'Perhatian';
        $colour = '#F59E0B';
        $message = 'Prestasi berada di antara ambang merah dan hijau.';

        $evaluationValue = (float) $actual;

        if ($setting->evaluation_direction === 'higher_is_better') {
            if ($evaluationValue >= $green) {
                $status = 'green';
                $statusLabel = 'Baik';
                $colour = '#16A34A';
                $message = 'Prestasi mencapai atau melepasi ambang hijau.';
            } elseif ($evaluationValue <= $red) {
                $status = 'red';
                $statusLabel = 'Kritikal';
                $colour = '#DC2626';
                $message = 'Prestasi berada pada atau di bawah ambang merah.';
            }
        } else {
            if ($evaluationValue <= $green) {
                $status = 'green';
                $statusLabel = 'Baik';
                $colour = '#16A34A';
                $message = 'Prestasi mencapai atau lebih baik daripada ambang hijau.';
            } elseif ($evaluationValue >= $red) {
                $status = 'red';
                $statusLabel = 'Kritikal';
                $colour = '#DC2626';
                $message = 'Prestasi berada pada atau melepasi ambang merah.';
            }
        }

        return [
            'indicator_code' => $indicator['code'],
            'indicator_name' => $indicator['name'],
            'actual' => (float) $actual,
            'unit' => $indicator['unit'],
            'green_threshold' => (float) $green,
            'red_threshold' => (float) $red,
            'status' => $status,
            'status_label' => $statusLabel,
            'colour' => $colour,
            'target_source' => $targetSource,
            'target_type' => $targetType,
            'target_label' => $targetLabel,
            'target_value' => $targetValue,
            'evaluation_basis' => $indicator['evaluation_basis'],
            'achievement_percentage' => $achievementPercentage,
            'expected_target_to_date' => $expectedTargetToDate,
            'evaluation_value' => $evaluationValue,
            'variance' => round($evaluationValue - (float) $green, 2),
            'message' => $message,
            'explanation' => $message,
        ];
    }

    private function prorateThresholdsToDate(float $monthlyGreen, float $monthlyRed, int $year, int $month, string $asOfDate): ?array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $asOf = Carbon::parse($asOfDate)->endOfDay();

        if ($asOf->lt($monthStart)) {
            return null;
        }

        $effectiveAsOf = $asOf->gt($monthEnd) ? $monthEnd : $asOf;
        $elapsedDays = (int) $effectiveAsOf->day;
        $totalDays = (int) $monthEnd->day;

        if ($totalDays <= 0) {
            return null;
        }

        $ratio = $elapsedDays / $totalDays;

        return [
            'green' => round($monthlyGreen * $ratio, 2),
            'red' => round($monthlyRed * $ratio, 2),
        ];
    }

    private function buildBtsProgressResult(
        float $actual,
        ?float $proratedTarget,
        ?Carbon $date,
        ?float $monthlyTarget = null
    ): array {
        $base = [
            'actual_bts_mtd' => round(max(0, $actual), 2),
            'monthly_target' => $monthlyTarget !== null ? round(max(0, $monthlyTarget), 2) : null,
            'prorated_target' => $proratedTarget !== null ? round(max(0, $proratedTarget), 2) : null,
            'elapsed_days' => $date?->day,
            'total_days' => $date?->daysInMonth,
            'as_of_date' => $date?->toDateString(),
            'achievement_percentage' => null,
            'variance' => null,
            'status' => 'grey',
            'status_label' => 'Tidak Dinilai',
            'colour' => '#9CA3AF',
        ];

        if ($date === null || $proratedTarget === null || $proratedTarget <= 0) {
            return $base;
        }

        $achievement = ($actual / $proratedTarget) * 100;
        $status = 'red';
        $statusLabel = 'Ketinggalan';
        $colour = '#DC2626';

        if ($achievement >= 95) {
            $status = 'green';
            $statusLabel = 'Mengikut Sasaran';
            $colour = '#16A34A';
        } elseif ($achievement >= 85) {
            $status = 'yellow';
            $statusLabel = 'Perlu Perhatian';
            $colour = '#F59E0B';
        }

        return array_merge($base, [
            'achievement_percentage' => round($achievement, 2),
            'variance' => round($actual - $proratedTarget, 2),
            'status' => $status,
            'status_label' => $statusLabel,
            'colour' => $colour,
        ]);
    }

    private function buildGreyResult(
        array $indicator,
        ?float $actual,
        string $statusLabel,
        string $message,
        ?KpiIndicatorSetting $setting = null,
        array $extra = []
    ): array {
        return array_merge([
            'indicator_code' => $indicator['code'],
            'indicator_name' => $indicator['name'],
            'actual' => $actual,
            'unit' => $indicator['unit'],
            'green_threshold' => $setting?->green_threshold,
            'red_threshold' => $setting?->red_threshold,
            'status' => 'grey',
            'status_label' => $statusLabel,
            'colour' => '#9CA3AF',
            'target_source' => $setting ? 'mill' : 'none',
            'target_type' => 'none',
            'target_label' => $statusLabel,
            'target_value' => null,
            'evaluation_basis' => $indicator['evaluation_basis'] ?? null,
            'achievement_percentage' => null,
            'expected_target_to_date' => null,
            'evaluation_value' => null,
            'variance' => null,
            'message' => $message,
            'explanation' => $message,
        ], $extra);
    }

    private function resolveWeakestResult(array $results): array
    {
        $weakest = $results[0] ?? [
            'status' => 'grey',
            'status_label' => 'Belum Ditetapkan',
            'colour' => '#9CA3AF',
        ];
        $weakestPriority = self::STATUS_PRIORITY[$weakest['status'] ?? 'grey'] ?? 0;

        foreach ($results as $result) {
            $priority = self::STATUS_PRIORITY[$result['status'] ?? 'grey'] ?? 0;
            if ($priority > $weakestPriority) {
                $weakest = $result;
                $weakestPriority = $priority;
            }
        }

        return $weakest;
    }
}
