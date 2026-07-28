<?php

namespace App\Services;

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
        $rows = $xpath->query('//table//tr[contains(concat(" ", normalize-space(@class), " "), " subsubhead ")]');

        if (! $rows || $rows->length === 0) {
            throw new RuntimeException('MPOB daily price rows were not found.');
        }

        $products = [
            'cpo' => ['name' => 'Crude Palm Oil (CPO)', 'price' => null, 'price_date' => null],
            'pk' => ['name' => 'Palm Kernel (PK)', 'price' => null, 'price_date' => null],
            'cpko' => ['name' => 'Crude Palm Kernel Oil (CPKO)', 'price' => null, 'price_date' => null],
        ];
        $columnByProduct = ['cpo' => 1, 'pk' => 5, 'cpko' => 9];

        foreach ($rows as $row) {
            $cells = $xpath->query('./th|./td', $row);
            if (! $cells || $cells->length < 10) {
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