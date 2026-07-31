<?php

namespace App\Services;

class ManagementMonthlyReportPresentationService
{
    private const STATUS_META = [
        'green' => ['label' => 'CAPAI', 'symbol' => '✓', 'colour' => '#15803D', 'background' => '#DCFCE7'],
        'yellow' => ['label' => 'PERHATIAN', 'symbol' => '!', 'colour' => '#A16207', 'background' => '#FEF3C7'],
        'red' => ['label' => 'TIDAK CAPAI', 'symbol' => '×', 'colour' => '#B91C1C', 'background' => '#FEE2E2'],
        'grey' => ['label' => 'KPI BELUM DITETAPKAN', 'symbol' => '–', 'colour' => '#4B5563', 'background' => '#F3F4F6'],
    ];

    public function prepare(array $dataset): array
    {
        $millScorecards = [];
        $statusCounts = ['green' => 0, 'yellow' => 0, 'red' => 0, 'grey' => 0];

        foreach ($dataset['mills'] as $millReport) {
            $scorecards = [];

            foreach ($millReport['kpi'] as $code => $result) {
                $status = $result['status'] ?? 'grey';
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                $scorecards[] = $this->buildScorecard($code, $result);
            }

            $millScorecards[] = [
                'mill' => $millReport['mill'],
                'items' => $scorecards,
            ];
        }

        return [
            'generated_at' => now()->translatedFormat('d F Y, H:i'),
            'status_meta' => self::STATUS_META,
            'status_counts' => $statusCounts,
            'mill_scorecards' => $millScorecards,
            'highlights' => $this->formatHighlights($dataset['highlights']),
            'has_records' => (int) ($dataset['overall']['operationalSummary']['record_days'] ?? 0) > 0,
        ];
    }

    private function buildScorecard(string $code, array $result): array
    {
        $status = $result['status'] ?? 'grey';
        $statusMeta = self::STATUS_META[$status] ?? self::STATUS_META['grey'];

        if ($status === 'grey') {
            $label = strtoupper((string) ($result['status_label'] ?? 'KPI Belum Ditetapkan'));
            $statusMeta['label'] = match ($label) {
                'TIDAK BERKENAAN' => 'TIDAK BERKENAAN',
                'TIADA DATA' => 'TIADA DATA',
                default => 'KPI BELUM DITETAPKAN',
            };
        }

        if ($code === 'bts') {
            return [
                'code' => $code,
                'name' => $result['indicator_name'] ?? 'Penerimaan & Pemprosesan BTS',
                'unit' => $result['unit'] ?? 'MT',
                'actuals' => [
                    ['label' => 'BTS Diterima', 'value' => $result['actual_bts_diterima'] ?? null],
                    ['label' => 'BTS Diproses', 'value' => $result['actual_bts_diproses'] ?? null],
                ],
                'target' => $result['target'] ?? null,
                'variances' => [
                    ['label' => 'Diterima', 'value' => $result['variance_bts_diterima'] ?? null],
                    ['label' => 'Diproses', 'value' => $result['variance_bts_diproses'] ?? null],
                ],
                'achievement' => null,
                'status' => $status,
                'status_meta' => $statusMeta,
            ];
        }

        return [
            'code' => $code,
            'name' => $result['indicator_name'] ?? $code,
            'unit' => $result['unit'] ?? null,
            'actuals' => [['label' => 'Actual', 'value' => $result['actual'] ?? null]],
            'target' => $result['green_threshold'] ?? null,
            'variances' => [['label' => 'Variance', 'value' => $result['variance'] ?? null]],
            'achievement' => $result['achievement_percentage'] ?? null,
            'status' => $status,
            'status_meta' => $statusMeta,
        ];
    }

    private function formatHighlights(array $highlights): array
    {
        return [
            'achievements' => array_map(
                fn (array $item) => sprintf(
                    '%s: %s mencapai sasaran.',
                    $item['mill'],
                    $this->indicatorLabel($item['indicator'])
                ),
                $highlights['achievements'] ?? []
            ),
            'attention' => array_map(
                fn (array $item) => sprintf(
                    '%s: %s berstatus %s.',
                    $item['mill'],
                    $this->indicatorLabel($item['indicator']),
                    self::STATUS_META[$item['status']]['label'] ?? 'PERHATIAN'
                ),
                $highlights['attentionItems'] ?? []
            ),
            'operations' => array_map(
                fn (array $item) => $this->formatOperationalObservation($item),
                $highlights['operationalObservations'] ?? []
            ),
        ];
    }

    private function formatOperationalObservation(array $item): string
    {
        if (($item['type'] ?? null) === 'recorded_operational_issue') {
            $text = sprintf('%s (%s): %s', $item['mill'], $item['date'], $item['issue']);

            return ! empty($item['corrective_action'])
                ? $text . '. Tindakan direkodkan: ' . $item['corrective_action'] . '.'
                : $text . '.';
        }

        return sprintf(
            '%s: downtime tertinggi pada %s, sebanyak %s jam.',
            $item['mill'] ?? 'Kilang',
            $item['date'] ?? '-',
            number_format((float) ($item['value'] ?? 0), 2)
        );
    }

    private function indicatorLabel(string $code): string
    {
        if ($code === 'bts') {
            return 'Penerimaan & Pemprosesan BTS';
        }

        return KpiEvaluationService::indicatorMap()[$code]['name'] ?? $code;
    }
}