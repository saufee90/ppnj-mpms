<?php

namespace App\Services;

use App\Models\Mill;
use App\Models\MillMonthlyHistory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HistoricalPerformanceImportService
{
    public const EXPECTED_TOTALS = [
        'KHG' => ['64650.82', '64650.82', '1524.22', '12246.00', '12012.48', '3181.00', '3292.10'],
        'BBJ' => ['42320.55', '42320.55', '1462.00', '8306.70', '8213.02', '1890.06', '1890.06'],
    ];

    public function dataset(): array
    {
        $handle = fopen(resource_path('data/mill-monthly-history-2026.csv'), 'r');
        if ($handle === false) {
            throw new RuntimeException('Historical source file could not be opened.');
        }

        try {
            $headers = ['mill_code', 'year', 'month', ...MillMonthlyHistory::FIELDS];
            if (fgetcsv($handle) !== $headers) {
                throw new RuntimeException('Historical source has unexpected columns.');
            }
            $rows = [];
            $totals = [];
            while (($values = fgetcsv($handle)) !== false) {
                if (count($values) !== count($headers)) {
                    throw new RuntimeException('Historical source has an invalid row.');
                }
                $row = array_combine($headers, $values);
                $key = $row['mill_code'].'-'.$row['month'];
                if (! isset(self::EXPECTED_TOTALS[$row['mill_code']])
                    || $row['year'] !== '2026'
                    || ! in_array($row['month'], ['1', '2', '3', '4', '5', '6'], true)
                    || isset($rows[$key])) {
                    throw new RuntimeException('Historical source has an unexpected or duplicate month.');
                }
                foreach (MillMonthlyHistory::FIELDS as $field) {
                    if (! preg_match('/^\d+\.\d{2}$/D', $row[$field])) {
                        throw new RuntimeException("Invalid historical amount: $key / $field.");
                    }
                    $totals[$row['mill_code']][$field] = ($totals[$row['mill_code']][$field] ?? 0)
                        + (int) str_replace('.', '', $row[$field]);
                }
                $rows[$key] = $row;
            }
            if (count($rows) !== 12) {
                throw new RuntimeException('Historical source must contain exactly twelve months.');
            }
            foreach (self::EXPECTED_TOTALS as $code => $expected) {
                foreach (MillMonthlyHistory::FIELDS as $index => $field) {
                    if (($totals[$code][$field] ?? null) !== (int) str_replace('.', '', $expected[$index])) {
                        throw new RuntimeException("Historical total does not reconcile: $code / $field.");
                    }
                }
            }

            return array_values($rows);
        } finally {
            fclose($handle);
        }
    }

    public function import(bool $dryRun = false): array
    {
        $rows = $this->dataset();
        $mills = Mill::query()->whereIn('code', array_keys(self::EXPECTED_TOTALS))->get()->keyBy('code');
        if ($mills->count() !== 2) {
            throw new RuntimeException('Both BBJ and KHG mill master records are required.');
        }

        return DB::transaction(function () use ($rows, $mills, $dryRun) {
            $result = ['inserted' => 0, 'unchanged' => 0];
            foreach ($rows as $row) {
                $key = ['mill_id' => $mills[$row['mill_code']]->id, 'year' => 2026, 'month' => (int) $row['month']];
                $existing = MillMonthlyHistory::query()->where($key)->lockForUpdate()->first();
                $amounts = array_intersect_key($row, array_flip(MillMonthlyHistory::FIELDS));
                if ($existing) {
                    foreach ($amounts as $field => $amount) {
                        if ($existing->{$field} !== $amount) {
                            throw new RuntimeException("Existing historical record differs: {$row['mill_code']} month {$row['month']} / $field. Nothing imported.");
                        }
                    }
                    $result['unchanged']++;
                } else {
                    if (! $dryRun) {
                        MillMonthlyHistory::create([...$key, ...$amounts]);
                    }
                    $result['inserted']++;
                }
            }

            return $result;
        });
    }
}
