<?php

namespace App\Services;

class ManagementReportSvgChartService
{
    private const COLOURS = ['#0B5D32', '#C9A227', '#2563EB', '#DC2626'];

    public function build(array $dataset): array
    {
        $trend = $dataset['overall']['trend'] ?? [];
        $singleMill = count($dataset['mills'] ?? []) === 1 ? $dataset['mills'][0] : null;
        $charts = [
            'bts' => $this->lineChart($trend, [
                ['key' => 'bts_diterima', 'label' => 'BTS Diterima'],
                ['key' => 'bts_diproses', 'label' => 'BTS Diproses'],
            ], 'MT'),
            'production' => $this->lineChart($trend, [
                ['key' => 'cpo', 'label' => 'CPO'],
                ['key' => 'pk', 'label' => 'PK'],
            ], 'MT'),
            'oer' => $this->dailyBarChart(
                $trend,
                ['key' => 'oer', 'label' => 'OER'],
                '%',
                $singleMill['kpi']['oer']['green_threshold'] ?? null
            ),
            'ker' => $this->dailyBarChart(
                $trend,
                ['key' => 'ker', 'label' => 'KER'],
                '%',
                $singleMill['kpi']['ker']['green_threshold'] ?? null
            ),
            'downtime' => $this->lineChart(
                $trend,
                [['key' => 'downtime_percentage', 'label' => 'Downtime']],
                '%',
                $singleMill['kpi']['downtime']['green_threshold'] ?? null
            ),
            'comparison' => [],
        ];

        if ($dataset['flags']['showMillComparison'] ?? false) {
            $comparison = $dataset['comparison'];
            $charts['comparison'] = [
                'volume' => $this->groupedBarChart($comparison, [
                    ['key' => 'bts_diproses', 'label' => 'BTS Diproses'],
                    ['key' => 'pengeluaran_cpo', 'label' => 'CPO'],
                    ['key' => 'pengeluaran_pk', 'label' => 'PK'],
                ], 'MT'),
                'extraction' => $this->groupedBarChart($comparison, [
                    ['key' => 'oer', 'label' => 'OER'],
                    ['key' => 'ker', 'label' => 'KER'],
                ], '%'),
                'throughput' => $this->groupedBarChart($comparison, [
                    ['key' => 'throughput', 'label' => 'Throughput'],
                ], 'MT/Jam'),
                'downtime' => $this->groupedBarChart($comparison, [
                    ['key' => 'downtime_percentage', 'label' => 'Downtime (lebih rendah lebih baik)'],
                ], '%'),
            ];
        }

        return $charts;
    }

    private function lineChart(array $rows, array $series, string $unit, ?float $target = null): ?string
    {
        if ($rows === []) {
            return null;
        }

        $width = 900;
        $height = 280;
        $left = 58;
        $right = 22;
        $top = 34;
        $bottom = 52;
        $plotWidth = $width - $left - $right;
        $plotHeight = $height - $top - $bottom;
        $values = [];

        foreach ($rows as $row) {
            foreach ($series as $definition) {
                if (($row[$definition['key']] ?? null) !== null) {
                    $values[] = (float) $row[$definition['key']];
                }
            }
        }

        if ($target !== null) {
            $values[] = $target;
        }

        $maximum = max(1.0, $values === [] ? 1.0 : max($values));
        $maximum *= 1.12;
        $svg = $this->svgStart($width, $height);
        $svg .= $this->grid($left, $top, $plotWidth, $plotHeight, $maximum, $unit);

        foreach ($series as $seriesIndex => $definition) {
            $points = [];
            foreach ($rows as $index => $row) {
                $value = $row[$definition['key']] ?? null;
                if ($value === null) {
                    continue;
                }
                $x = $left + (count($rows) === 1 ? $plotWidth / 2 : ($index / (count($rows) - 1)) * $plotWidth);
                $y = $top + $plotHeight - (((float) $value / $maximum) * $plotHeight);
                $points[] = round($x, 2) . ',' . round($y, 2);
                $svg .= sprintf('<circle cx="%.2f" cy="%.2f" r="3" fill="%s"/>', $x, $y, self::COLOURS[$seriesIndex]);
            }
            if ($points !== []) {
                $svg .= sprintf('<polyline points="%s" fill="none" stroke="%s" stroke-width="3"/>', implode(' ', $points), self::COLOURS[$seriesIndex]);
            }
            $legendX = $left + ($seriesIndex * 180);
            $svg .= sprintf('<line x1="%d" y1="16" x2="%d" y2="16" stroke="%s" stroke-width="4"/>', $legendX, $legendX + 24, self::COLOURS[$seriesIndex]);
            $svg .= sprintf('<text x="%d" y="20" class="legend">%s</text>', $legendX + 30, $this->escape($definition['label']));
        }

        if ($target !== null) {
            $targetY = $top + $plotHeight - (($target / $maximum) * $plotHeight);
            $svg .= sprintf('<line x1="%d" y1="%.2f" x2="%d" y2="%.2f" stroke="#7C3AED" stroke-width="2" stroke-dasharray="7 5"/>', $left, $targetY, $left + $plotWidth, $targetY);
            $svg .= sprintf('<text x="%d" y="%.2f" class="target">Sasaran %s %s</text>', $left + 6, max($top + 11, $targetY - 5), number_format($target, 2), $this->escape($unit));
        }

        $labelStep = max(1, (int) ceil(count($rows) / 8));
        foreach ($rows as $index => $row) {
            if ($index % $labelStep !== 0 && $index !== count($rows) - 1) {
                continue;
            }
            $x = $left + (count($rows) === 1 ? $plotWidth / 2 : ($index / (count($rows) - 1)) * $plotWidth);
            $label = substr((string) ($row['date'] ?? ''), 8, 2);
            $svg .= sprintf('<text x="%.2f" y="%d" text-anchor="middle" class="axis">%s</text>', $x, $height - 20, $this->escape($label));
        }

        return $this->toDataUri($svg . '</svg>');
    }

    private function dailyBarChart(array $rows, array $series, string $unit, ?float $target = null): ?string
    {
        if ($rows === []) {
            return null;
        }

        $width = 900;
        $height = 280;
        $left = 58;
        $right = 22;
        $top = 34;
        $bottom = 52;
        $plotWidth = $width - $left - $right;
        $plotHeight = $height - $top - $bottom;
        $values = array_values(array_filter(
            array_map(fn (array $row) => $row[$series['key']] ?? null, $rows),
            fn ($value) => $value !== null
        ));

        if ($target !== null) {
            $values[] = $target;
        }

        $maximum = max(1.0, $values === [] ? 1.0 : max(array_map('floatval', $values))) * 1.12;
        $svg = $this->svgStart($width, $height);
        $svg .= $this->grid($left, $top, $plotWidth, $plotHeight, $maximum, $unit);
        $slotWidth = $plotWidth / count($rows);
        $barWidth = min(24.0, $slotWidth * 0.72);

        foreach ($rows as $index => $row) {
            $value = $row[$series['key']] ?? null;
            if ($value === null) {
                continue;
            }

            $barHeight = ((float) $value / $maximum) * $plotHeight;
            $x = $left + (($index + 0.5) * $slotWidth) - ($barWidth / 2);
            $y = $top + $plotHeight - $barHeight;
            $svg .= sprintf('<rect class="bar" x="%.2f" y="%.2f" width="%.2f" height="%.2f" fill="%s" rx="2"/>', $x, $y, $barWidth, $barHeight, self::COLOURS[0]);
        }

        $svg .= sprintf('<rect x="%d" y="8" width="15" height="15" fill="%s"/><text x="%d" y="20" class="legend">%s</text>', $left, self::COLOURS[0], $left + 22, $this->escape($series['label']));

        if ($target !== null) {
            $targetY = $top + $plotHeight - (($target / $maximum) * $plotHeight);
            $svg .= sprintf('<line x1="%d" y1="%.2f" x2="%d" y2="%.2f" stroke="#7C3AED" stroke-width="2" stroke-dasharray="7 5"/>', $left, $targetY, $left + $plotWidth, $targetY);
            $svg .= sprintf('<text x="%d" y="%.2f" class="target">Sasaran %s %s</text>', $left + 6, max($top + 11, $targetY - 5), number_format($target, 2), $this->escape($unit));
        }

        $labelStep = max(1, (int) ceil(count($rows) / 8));
        foreach ($rows as $index => $row) {
            if ($index % $labelStep !== 0 && $index !== count($rows) - 1) {
                continue;
            }

            $x = $left + (($index + 0.5) * $slotWidth);
            $label = substr((string) ($row['date'] ?? ''), 8, 2);
            $svg .= sprintf('<text x="%.2f" y="%d" text-anchor="middle" class="axis">%s</text>', $x, $height - 20, $this->escape($label));
        }

        return $this->toDataUri($svg . '</svg>');
    }

    private function groupedBarChart(array $mills, array $metrics, string $unit): ?string
    {
        if ($mills === []) {
            return null;
        }

        $width = 900;
        $height = 280;
        $left = 58;
        $top = 34;
        $bottom = 54;
        $plotWidth = 820;
        $plotHeight = $height - $top - $bottom;
        $values = [];
        foreach ($mills as $mill) {
            foreach ($metrics as $metric) {
                $values[] = (float) ($mill['metrics'][$metric['key']] ?? 0);
            }
        }
        $maximum = max(1.0, max($values)) * 1.12;
        $svg = $this->svgStart($width, $height) . $this->grid($left, $top, $plotWidth, $plotHeight, $maximum, $unit);
        $groupWidth = $plotWidth / count($metrics);
        $barWidth = min(58, ($groupWidth * 0.7) / count($mills));

        foreach ($metrics as $metricIndex => $metric) {
            $groupCenter = $left + (($metricIndex + 0.5) * $groupWidth);
            foreach ($mills as $millIndex => $mill) {
                $value = (float) ($mill['metrics'][$metric['key']] ?? 0);
                $barHeight = ($value / $maximum) * $plotHeight;
                $x = $groupCenter - ((count($mills) * $barWidth) / 2) + ($millIndex * $barWidth);
                $y = $top + $plotHeight - $barHeight;
                $svg .= sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" fill="%s" rx="2"/>', $x, $y, $barWidth - 5, $barHeight, self::COLOURS[$millIndex]);
                $svg .= sprintf('<text x="%.2f" y="%.2f" text-anchor="middle" class="value">%s</text>', $x + (($barWidth - 5) / 2), max($top + 10, $y - 5), number_format($value, 2));
            }
            $svg .= sprintf('<text x="%.2f" y="%d" text-anchor="middle" class="axis">%s</text>', $groupCenter, $height - 20, $this->escape($metric['label']));
        }

        foreach ($mills as $index => $mill) {
            $legendX = $left + ($index * 250);
            $svg .= sprintf('<rect x="%d" y="8" width="15" height="15" fill="%s"/><text x="%d" y="20" class="legend">%s</text>', $legendX, self::COLOURS[$index], $legendX + 22, $this->escape($mill['name']));
        }

        return $this->toDataUri($svg . '</svg>');
    }

    private function svgStart(int $width, int $height): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d"><style>text{font-family:DejaVu Sans,sans-serif}.axis{font-size:12px;fill:#64748B}.legend{font-size:12px;fill:#334155}.value{font-size:10px;fill:#334155}.target{font-size:11px;fill:#6D28D9;font-weight:bold}</style><rect width="100%%" height="100%%" fill="#FFFFFF"/>',
            $width,
            $height,
            $width,
            $height
        );
    }

    private function grid(int $left, int $top, int $width, int $height, float $maximum, string $unit): string
    {
        $svg = '';
        for ($step = 0; $step <= 4; $step++) {
            $y = $top + (($step / 4) * $height);
            $value = $maximum * (1 - ($step / 4));
            $svg .= sprintf('<line x1="%d" y1="%.2f" x2="%d" y2="%.2f" stroke="#E2E8F0" stroke-width="1"/>', $left, $y, $left + $width, $y);
            $svg .= sprintf('<text x="%d" y="%.2f" text-anchor="end" class="axis">%s</text>', $left - 7, $y + 4, number_format($value, 1));
        }
        $svg .= sprintf('<text x="8" y="18" class="axis">%s</text>', $this->escape($unit));

        return $svg;
    }

    private function toDataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}