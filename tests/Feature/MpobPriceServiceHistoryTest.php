<?php

namespace Tests\Feature;

use App\Models\MpobPriceHistory;
use App\Services\MpobPriceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MpobPriceServiceHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_refresh_persists_all_valid_monthly_rows_and_maps_categories_correctly(): void
    {
        $this->resetCacheKeys();

        Http::fake([
            '*' => Http::response($this->monthlyFixtureHtml(), 200),
        ]);

        $service = app(MpobPriceService::class);
        $service->refresh(Carbon::parse('2026-07-30'));

        $this->assertDatabaseCount('mpob_price_histories', 11);

        $this->assertHistoryValue('2026-07-01', 'cpo', 4481.50);
        $this->assertHistoryValue('2026-07-01', 'pk', 3552.00);
        $this->assertHistoryValue('2026-07-01', 'cpko', 7509.50);

        $this->assertHistoryValue('2026-07-02', 'cpo', 4499.00);
        $this->assertHistoryValue('2026-07-02', 'cpko', 7500.50);
        $this->assertHistoryMissing('2026-07-02', 'pk');

        $this->assertHistoryMissing('2026-07-03', 'cpo');
        $this->assertHistoryMissing('2026-07-03', 'cpko');
        $this->assertHistoryValue('2026-07-03', 'pk', 3600.00);

        // Average/Purata row must not be persisted.
        $this->assertEquals(
            0,
            MpobPriceHistory::query()
                ->where('category', 'cpo')
                ->whereDate('trade_date', '2026-07-31')
                ->where('price', 4495.00)
                ->count()
        );
    }

    public function test_refresh_twice_does_not_duplicate_and_updates_existing_day_price(): void
    {
        $this->resetCacheKeys();

        $service = app(MpobPriceService::class);

        Http::fake([
            '*' => Http::response($this->monthlyFixtureHtml(), 200),
        ]);

        $service->refresh(Carbon::parse('2026-07-30'));

        MpobPriceHistory::query()
            ->where('category', 'cpo')
            ->whereDate('trade_date', '2026-07-29')
            ->update(['price' => 9999.99]);

        $secondPayload = $service->refresh(Carbon::parse('2026-07-30'));

        $this->assertDatabaseCount('mpob_price_histories', 11);

        $this->assertEquals(
            1,
            MpobPriceHistory::query()
                ->where('category', 'cpo')
                ->whereDate('trade_date', '2026-07-29')
                ->count()
        );

        $updated = MpobPriceHistory::query()
            ->where('category', 'cpo')
            ->whereDate('trade_date', '2026-07-29')
            ->first();

        $this->assertNotNull($updated);
        $this->assertEquals(4515.00, (float) $updated->price);
        $this->assertEquals(4515.00, (float) ($secondPayload['products']['cpo']['price'] ?? 0));
    }

    public function test_history_parsing_failure_does_not_break_current_price_refresh(): void
    {
        $this->resetCacheKeys();
        Log::spy();

        Http::fake([
            '*' => Http::response($this->monthlyFixtureHtml(), 200),
        ]);

        /** @var MpobPriceService&\Mockery\MockInterface $service */
        $service = Mockery::mock(MpobPriceService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('persistMonthlyHistoryFromHtml')->once()->andThrow(new RuntimeException('forced history parse failure'));

        $payload = $service->refresh(Carbon::parse('2026-07-30'));

        $this->assertEquals(4515.00, (float) ($payload['products']['cpo']['price'] ?? 0));
        $this->assertEquals('2026-07-29', (string) ($payload['products']['cpo']['price_date'] ?? ''));
        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) {
            return $message === 'MPOB history persistence failed.'
                && str_contains((string) ($context['message'] ?? ''), 'forced history parse failure');
        })->once();
    }

    public function test_refresh_populates_more_than_one_trend_point_for_dashboard_source(): void
    {
        $this->resetCacheKeys();

        Http::fake([
            '*' => Http::response($this->monthlyFixtureHtml(), 200),
        ]);

        $service = app(MpobPriceService::class);
        $service->refresh(Carbon::parse('2026-07-30'));

        $this->assertGreaterThan(
            1,
            MpobPriceHistory::query()
                ->where('category', 'cpo')
                ->count()
        );
    }

    public function test_refresh_parses_structure_based_html_without_css_classes(): void
    {
        $this->resetCacheKeys();

        Http::fake([
            '*' => Http::response($this->monthlyFixtureHtmlWithoutClasses(), 200),
        ]);

        $service = app(MpobPriceService::class);
        $service->refresh(Carbon::parse('2026-07-30'));

        $this->assertDatabaseCount('mpob_price_histories', 11);
        $this->assertHistoryValue('2026-07-01', 'cpo', 4481.50);
        $this->assertHistoryValue('2026-07-01', 'pk', 3552.00);
        $this->assertHistoryValue('2026-07-01', 'cpko', 7509.50);
        $this->assertHistoryMissing('2026-07-02', 'pk');
        $this->assertHistoryValue('2026-07-29', 'cpko', 7791.00);
    }

    private function resetCacheKeys(): void
    {
        Cache::store('file')->forget('mpob.latest_peninsular_prices');
        Cache::store('file')->forget('mpob.latest_peninsular_prices_status');
    }

    private function monthlyFixtureHtml(array $overrides = []): string
    {
        $rows = [
            1 => ['cpo' => '4,481.50', 'pk' => '3,552.00', 'cpko' => '7,509.50'],
            2 => ['cpo' => '4,499.00', 'pk' => 'NT', 'cpko' => '7,500.50'],
            3 => ['cpo' => 'NT', 'pk' => '3,600.00', 'cpko' => 'PH'],
            4 => ['cpo' => 'PH', 'pk' => '3,610.00', 'cpko' => ''],
            5 => ['cpo' => 'abc', 'pk' => '3,620.00', 'cpko' => '0'],
            29 => ['cpo' => '4,515.00', 'pk' => '3,680.00', 'cpko' => '7,791.00'],
        ];

        foreach ($overrides as $day => $values) {
            if (! isset($rows[$day])) {
                continue;
            }

            $rows[$day] = array_merge($rows[$day], $values);
        }

        $bodyRows = '';
        foreach ($rows as $day => $values) {
            $cpo = $values['cpo'] ?? 'NT';
            $pk = $values['pk'] ?? 'NT';
            $cpko = $values['cpko'] ?? 'NT';

            $bodyRows .= '<tr class="subsubhead">'
                . '<td>' . $day . '</td>'
                . '<td>' . $cpo . '</td><td>NT</td><td>NT</td><td>NT</td>'
                . '<td>' . $pk . '</td><td>NT</td><td>NT</td><td>NT</td>'
                . '<td>' . $cpko . '</td><td>NT</td><td>NT</td><td>NT</td>'
                . '</tr>';
        }

        return '<html><body>'
            . '<table>'
            . '<tr class="head">'
            . '<th>DateTarikh</th><th>CPO</th><th></th><th></th><th></th><th>PK</th><th></th><th></th><th></th><th>CPKO</th><th></th><th></th><th></th>'
            . '</tr>'
            . '<tr class="subhead">'
            . '<th>July2026</th><th>Aug2026</th><th>Sept2026</th><th>Oct2026</th>'
            . '<th>July2026</th><th>Aug2026</th><th>Sept2026</th><th>Oct2026</th>'
            . '<th>July2026</th><th>Aug2026</th><th>Sept2026</th><th>Oct2026</th>'
            . '</tr>'
            . $bodyRows
            . '<tr class="head">'
            . '<td>AveragePurata</td><td>4,495.00</td><td>4,539.50</td><td>4,564.50</td><td>NT</td><td>3,690.00</td><td>3,780.50</td><td>NT</td><td>NT</td><td>7,809.00</td><td>7,930.50</td><td>7,946.00</td><td>7,820.00</td>'
            . '</tr>'
            . '</table>'
            . '<div>Last update : 30/07/2026 4.30 PM</div>'
            . '</body></html>';
    }

    private function monthlyFixtureHtmlWithoutClasses(array $overrides = []): string
    {
        return str_replace(
            [' class="subsubhead"', ' class="head"', ' class="subhead"'],
            ['', '', ''],
            $this->monthlyFixtureHtml($overrides)
        );
    }

    private function assertHistoryValue(string $tradeDate, string $category, float $expectedPrice): void
    {
        $row = MpobPriceHistory::query()
            ->where('category', $category)
            ->whereDate('trade_date', $tradeDate)
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals($expectedPrice, (float) $row->price);
    }

    private function assertHistoryMissing(string $tradeDate, string $category): void
    {
        $this->assertEquals(
            0,
            MpobPriceHistory::query()
                ->where('category', $category)
                ->whereDate('trade_date', $tradeDate)
                ->count()
        );
    }
}
