<?php

namespace App\Services;

use App\Models\KpiIndicatorSetting;
use Carbon\Carbon;

class KpiEvaluationService
{
    private const NOTE_2026 = 'Pengiraan YTD tahun 2026 adalah berdasarkan data MPS bermula 1 Julai 2026.';

    /**
     * Katalog KPI fasa 2 (FFA legacy tidak dipaparkan dalam ringkasan baharu).
     */
    public static function indicatorCatalog(): array
    {
        return [
            [
                'code' => 'total_bts_diterima',
                'name' => 'Total BTS Diterima',
                'unit' => 'MT',
                'direction' => 'higher_is_better',
                'evaluation_basis' => 'monthly_flow',
                'section' => 'Penerimaan dan Pemprosesan BTS',
                'supports_monthly_target' => true,
            ],
            [
                'code' => 'bts_diproses',
                'name' => 'BTS Diproses',
                'unit' => 'MT',
                'direction' => 'higher_is_better',
                'evaluation_basis' => 'monthly_flow',
                'section' => 'Penerimaan dan Pemprosesan BTS',
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
                'name' => 'Downtime (Jam)',
                'unit' => 'Jam',
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

        return KpiIndicatorSetting::query()
            ->where('indicator_code', $indicatorCode)
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
            'message' => $message,
            'explanation' => $message,
        ], $extra);
    }
}
