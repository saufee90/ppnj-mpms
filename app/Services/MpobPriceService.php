<?php

namespace App\Services;

use App\Models\MpobPriceHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MpobPriceService
{
    public const SOURCE_URL = 'https://bepi.mpob.gov.my/index.php?option=com_content&view=article&id=1030';

    private const PRICE_URL = 'https://price.mpob.gov.my/dailys/pn_cpo';
    private const CACHE_KEY = 'mpob.latest_peninsular_prices';
    private const STATUS_KEY = 'mpob.latest_peninsular_prices_status';
    private const FRESH_FOR_HOURS = 6;

    public function getForDashboard(): array
    {
        $cached = $this->cachedPrices();

        if ($cached && ! $this->isStale($cached)) {
            return $this->withStatus($cached);
        }

        try {
            return $this->refresh();
        } catch (\Throwable $exception) {
            Log::warning('MPOB price refresh failed.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->withStatus($cached ?: $this->emptyPayload());
        }
    }

    public function refresh(?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $url = self::PRICE_URL . '/' . $asOf->format('j/n/Y');

        try {
            $response = Http::withHeaders([
                'Accept' => 'text/html,application/xhtml+xml',
                'Referer' => 'https://price.mpob.gov.my/daily',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
            ])->connectTimeout(5)->timeout(12)->get($url);

            if (! $response->successful()) {
                throw new RuntimeException('MPOB returned HTTP ' . $response->status() . '.');
            }

            $parsed = $this->parseHtml($response->body(), $asOf);
            $previous = $this->cachedPrices();

            foreach ($parsed['products'] as $code => $product) {
                if ($product['price'] === null && isset($previous['products'][$code])) {
                    $parsed['products'][$code] = $previous['products'][$code];
                }
            }

            if (collect($parsed['products'])->every(fn (array $product) => $product['price'] === null)) {
                throw new RuntimeException('No valid CPO, PK or CPKO price was found.');
            }

            $parsed['refreshed_at'] = now()->toIso8601String();

            try {
                $this->persistMonthlyHistoryFromHtml(
                    $response->body(),
                    $asOf,
                    Carbon::parse($parsed['refreshed_at'])
                );
            } catch (\Throwable $historyException) {
                Log::warning('MPOB history persistence failed.', [
                    'message' => $historyException->getMessage(),
                    'as_of' => $asOf->toDateString(),
                    'source_url' => $url,
                ]);
            }

            Cache::store('file')->forever(self::CACHE_KEY, $parsed);
            Cache::store('file')->forever(self::STATUS_KEY, [
                'success' => true,
                'checked_at' => now()->toIso8601String(),
            ]);

            return $this->withStatus($parsed);
        } catch (\Throwable $exception) {
            Cache::store('file')->forever(self::STATUS_KEY, [
                'success' => false,
                'checked_at' => now()->toIso8601String(),
            ]);

            throw $exception;
        }
    }

    public function parseHtml(string $html, Carbon $asOf): array
    {
        $document = new \DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (! $loaded) {
            throw new RuntimeException('MPOB price page could not be parsed.');
        }

        $xpath = new \DOMXPath($document);
        $table = $this->resolveTargetPriceTable($xpath);
        $columnByProduct = $this->resolveCurrentMonthColumns($xpath, $table, $asOf);
        $rows = $this->resolveDataRows($xpath, $table);

        if (! $rows || $rows->length === 0) {
            throw new RuntimeException('MPOB daily price rows were not found.');
        }

        $products = [
            'cpo' => ['name' => 'Crude Palm Oil (CPO)', 'price' => null, 'price_date' => null],
            'pk' => ['name' => 'Palm Kernel (PK)', 'price' => null, 'price_date' => null],
            'cpko' => ['name' => 'Crude Palm Kernel Oil (CPKO)', 'price' => null, 'price_date' => null],
        ];

        foreach ($rows as $row) {
            $cells = $xpath->query('./th|./td', $row);
            if (! $cells || $cells->length < 13) {
                continue;
            }

            $day = filter_var(trim($cells->item(0)->textContent), FILTER_VALIDATE_INT);
            if (! $day || ! checkdate($asOf->month, $day, $asOf->year)) {
                continue;
            }

            foreach ($columnByProduct as $code => $column) {
                $value = $this->parsePrice($cells->item($column)?->textContent ?? '');
                if ($value !== null) {
                    $products[$code]['price'] = $value;
                    $products[$code]['price_date'] = Carbon::create(
                        $asOf->year,
                        $asOf->month,
                        $day
                    )->toDateString();
                }
            }
        }

        $lastUpdate = null;
        if (preg_match('/Last\s+update\s*:\s*([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4}\s+[0-9]{1,2}[.:][0-9]{2}\s*(?:AM|PM)?)/i', $document->textContent, $matches)) {
            $lastUpdate = trim($matches[1]);
        }

        return [
            'products' => $products,
            'mpob_last_update' => $lastUpdate,
            'source_url' => self::SOURCE_URL,
            'refreshed_at' => null,
        ];
    }

    private function parsePrice(string $value): ?float
    {
        $value = trim(str_replace("\xc2\xa0", ' ', $value));
        if ($value === '' || in_array(strtoupper($value), ['NT', 'PH'], true)) {
            return null;
        }

        $numeric = str_replace(',', '', $value);

        return is_numeric($numeric) ? round((float) $numeric, 2) : null;
    }

    protected function persistMonthlyHistoryFromHtml(string $html, Carbon $asOf, ?Carbon $sourceCheckedAt): void
    {
        $document = new \DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (! $loaded) {
            throw new RuntimeException('MPOB monthly history page could not be parsed.');
        }

        $xpath = new \DOMXPath($document);
        $table = $this->resolveTargetPriceTable($xpath);
        $columnByProduct = $this->resolveCurrentMonthColumns($xpath, $table, $asOf);
        $rows = $this->resolveDataRows($xpath, $table);

        if (! $rows || $rows->length === 0) {
            throw new RuntimeException('MPOB monthly history rows were not found.');
        }

        foreach ($rows as $row) {
            $cells = $xpath->query('./th|./td', $row);
            if (! $cells || $cells->length < 13) {
                continue;
            }

            $day = filter_var(trim($cells->item(0)->textContent), FILTER_VALIDATE_INT);
            if (! $day || ! checkdate($asOf->month, $day, $asOf->year)) {
                continue;
            }

            $tradeDate = Carbon::create($asOf->year, $asOf->month, $day)->startOfDay();

            foreach ($columnByProduct as $category => $columnIndex) {
                $value = $this->parsePrice($cells->item($columnIndex)?->textContent ?? '');
                if ($value === null || $value <= 0) {
                    continue;
                }

                MpobPriceHistory::query()->updateOrCreate(
                    [
                        'trade_date' => $tradeDate,
                        'category' => $category,
                    ],
                    [
                        'price' => round((float) $value, 2),
                        'source_checked_at' => $sourceCheckedAt,
                    ]
                );
            }
        }
    }

    private function resolveTargetPriceTable(\DOMXPath $xpath): \DOMNode
    {
        $tables = $xpath->query('//table');
        if (! $tables || $tables->length === 0) {
            throw new RuntimeException('MPOB price table was not found.');
        }

        foreach ($tables as $table) {
            $text = strtolower(preg_replace('/\s+/', ' ', $table->textContent ?? ''));
            $hasDate = str_contains($text, 'date') || str_contains($text, 'tarikh');
            $hasCpo = str_contains($text, 'crude palm oil') || str_contains($text, 'cpo');
            $hasPk = str_contains($text, 'palm kernel') || str_contains($text, 'pk');
            $hasCpko = str_contains($text, 'crude palm kernel oil') || str_contains($text, 'cpko');

            if (
                $hasDate
                && $hasCpo
                && $hasPk
                && $hasCpko
            ) {
                return $table;
            }
        }

        // Structure fallback when text markers are compressed or changed.
        foreach ($tables as $table) {
            if ($this->findMonthHeaderRowByStructure($xpath, $table) && $this->resolveDataRows($xpath, $table)?->length > 0) {
                return $table;
            }
        }

        throw new RuntimeException('MPOB target table markers (Date/Tarikh, CPO, PK, CPKO) were not found.');
    }

    private function resolveCurrentMonthColumns(\DOMXPath $xpath, \DOMNode $table, Carbon $asOf): array
    {
        // Fast path: known class selector.
        $subheadRow = $xpath->query('.//tr[contains(concat(" ", normalize-space(@class), " "), " subhead ")]', $table)->item(0);

        // Fallback: detect month row by structure (12 cells with month-year labels).
        if (! $subheadRow) {
            $subheadRow = $this->findMonthHeaderRowByStructure($xpath, $table);
        }

        if (! $subheadRow) {
            throw new RuntimeException('MPOB monthly header row was not found via class or structure detection.');
        }

        $cells = $xpath->query('./th|./td', $subheadRow);
        if (! $cells || $cells->length < 12) {
            throw new RuntimeException('MPOB monthly header columns are incomplete.');
        }

        $labels = [];
        for ($i = 0; $i < 12; $i++) {
            $labels[] = trim((string) $cells->item($i)?->textContent);
        }

        $offsets = [
            'cpo' => $this->resolveMonthOffset(array_slice($labels, 0, 4), $asOf),
            'pk' => $this->resolveMonthOffset(array_slice($labels, 4, 4), $asOf),
            'cpko' => $this->resolveMonthOffset(array_slice($labels, 8, 4), $asOf),
        ];

        if (in_array(null, $offsets, true)) {
            throw new RuntimeException('MPOB current-month columns could not be resolved from monthly header.');
        }

        return [
            'cpo' => 1 + $offsets['cpo'],
            'pk' => 5 + $offsets['pk'],
            'cpko' => 9 + $offsets['cpko'],
        ];
    }

    private function resolveDataRows(\DOMXPath $xpath, \DOMNode $table): ?\DOMNodeList
    {
        // Fast path: known class selector.
        $classRows = $xpath->query('.//tr[contains(concat(" ", normalize-space(@class), " "), " subsubhead ")]', $table);
        if ($classRows && $classRows->length > 0) {
            return $classRows;
        }

        // Fallback: structure-driven row detection.
        return $xpath->query('.//tr[count(./th|./td) >= 13 and normalize-space((./th|./td)[1]) != "" and number(normalize-space((./th|./td)[1])) = number(normalize-space((./th|./td)[1])) and number(normalize-space((./th|./td)[1])) >= 1 and number(normalize-space((./th|./td)[1])) <= 31]', $table);
    }

    private function findMonthHeaderRowByStructure(\DOMXPath $xpath, \DOMNode $table): ?\DOMNode
    {
        $rows = $xpath->query('.//tr', $table);
        if (! $rows) {
            return null;
        }

        foreach ($rows as $row) {
            $cells = $xpath->query('./th|./td', $row);
            if (! $cells || $cells->length < 12) {
                continue;
            }

            $labels = [];
            for ($i = 0; $i < 12; $i++) {
                $labels[] = trim((string) $cells->item($i)?->textContent);
            }

            $monthLabelCount = 0;
            foreach ($labels as $label) {
                if ($this->extractMonthYear($label) !== null) {
                    $monthLabelCount++;
                }
            }

            if ($monthLabelCount >= 3) {
                return $row;
            }
        }

        return null;
    }

    private function resolveMonthOffset(array $labels, Carbon $asOf): ?int
    {
        foreach ($labels as $index => $label) {
            $monthYear = $this->extractMonthYear($label);
            if (! $monthYear) {
                continue;
            }

            if ($monthYear['month'] === $asOf->month && $monthYear['year'] === $asOf->year) {
                return $index;
            }
        }

        return null;
    }

    private function extractMonthYear(string $label): ?array
    {
        if (! preg_match('/([A-Za-z]+)\s*([0-9]{4})/', $label, $matches)) {
            return null;
        }

        $monthMap = [
            'jan' => 1,
            'january' => 1,
            'feb' => 2,
            'february' => 2,
            'mar' => 3,
            'march' => 3,
            'apr' => 4,
            'april' => 4,
            'may' => 5,
            'jun' => 6,
            'june' => 6,
            'jul' => 7,
            'july' => 7,
            'aug' => 8,
            'august' => 8,
            'sep' => 9,
            'sept' => 9,
            'september' => 9,
            'oct' => 10,
            'october' => 10,
            'nov' => 11,
            'november' => 11,
            'dec' => 12,
            'december' => 12,
        ];

        $monthToken = strtolower($matches[1]);
        $month = $monthMap[$monthToken] ?? null;
        if ($month === null) {
            return null;
        }

        return [
            'month' => $month,
            'year' => (int) $matches[2],
        ];
    }

    private function cachedPrices(): ?array
    {
        $cached = Cache::store('file')->get(self::CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    private function isStale(array $payload): bool
    {
        if (empty($payload['refreshed_at'])) {
            return true;
        }

        return Carbon::parse($payload['refreshed_at'])->lt(now()->subHours(self::FRESH_FOR_HOURS));
    }

    private function withStatus(array $payload): array
    {
        $status = Cache::store('file')->get(self::STATUS_KEY, []);
        $payload['source_available'] = (bool) ($status['success'] ?? false);
        $payload['checked_at'] = $status['checked_at'] ?? null;

        return $payload;
    }

    private function emptyPayload(): array
    {
        return [
            'products' => [
                'cpo' => ['name' => 'Crude Palm Oil (CPO)', 'price' => null, 'price_date' => null],
                'pk' => ['name' => 'Palm Kernel (PK)', 'price' => null, 'price_date' => null],
                'cpko' => ['name' => 'Crude Palm Kernel Oil (CPKO)', 'price' => null, 'price_date' => null],
            ],
            'mpob_last_update' => null,
            'source_url' => self::SOURCE_URL,
            'refreshed_at' => null,
        ];
    }
}