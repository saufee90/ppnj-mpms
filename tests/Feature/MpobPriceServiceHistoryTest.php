<?php

namespace Tests\Feature;

use App\Models\MpobPriceHistory;
use App\Services\MpobPriceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class MpobPriceServiceHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_refresh_uses_upsert_behavior_for_same_trade_date_and_category(): void
    {
        Cache::store('file')->forget('mpob.latest_peninsular_prices');
        Cache::store('file')->forget('mpob.latest_peninsular_prices_status');

        Http::fake([
            '*' => Http::response('<html><body>ok</body></html>', 200),
        ]);

        /** @var MpobPriceService&\Mockery\MockInterface $service */
        $service = Mockery::mock(MpobPriceService::class)->makePartial();
        $service->shouldReceive('parseHtml')->twice()->andReturn(
            $this->parsedPayload(4500.00, 3800.00, 8000.00, '2026-07-30'),
            $this->parsedPayload(4550.00, 3800.00, 8000.00, '2026-07-30')
        );

        $service->refresh(Carbon::parse('2026-07-30'));
        $service->refresh(Carbon::parse('2026-07-30'));

        $this->assertDatabaseCount('mpob_price_histories', 3);
        $this->assertEquals(
            1,
            MpobPriceHistory::query()
                ->where('category', 'cpo')
                ->whereDate('trade_date', '2026-07-30')
                ->count()
        );
        $cpoHistory = MpobPriceHistory::query()
            ->where('category', 'cpo')
            ->whereDate('trade_date', '2026-07-30')
            ->first();

        $this->assertNotNull($cpoHistory);
        $this->assertEquals(4550.00, (float) $cpoHistory->price);
    }

    public function test_refresh_does_not_store_null_or_zero_prices(): void
    {
        Cache::store('file')->forget('mpob.latest_peninsular_prices');
        Cache::store('file')->forget('mpob.latest_peninsular_prices_status');

        Http::fake([
            '*' => Http::response('<html><body>ok</body></html>', 200),
        ]);

        /** @var MpobPriceService&\Mockery\MockInterface $service */
        $service = Mockery::mock(MpobPriceService::class)->makePartial();
        $service->shouldReceive('parseHtml')->once()->andReturn([
            'products' => [
                'cpo' => ['name' => 'Crude Palm Oil (CPO)', 'price' => 0, 'price_date' => '2026-07-30'],
                'pk' => ['name' => 'Palm Kernel (PK)', 'price' => null, 'price_date' => '2026-07-30'],
                'cpko' => ['name' => 'Crude Palm Kernel Oil (CPKO)', 'price' => 8123.45, 'price_date' => '2026-07-30'],
            ],
            'mpob_last_update' => '30/07/2026 5.00 PM',
            'source_url' => MpobPriceService::SOURCE_URL,
            'refreshed_at' => null,
        ]);

        $service->refresh(Carbon::parse('2026-07-30'));

        $this->assertDatabaseCount('mpob_price_histories', 1);
        $cpkoHistory = MpobPriceHistory::query()
            ->where('category', 'cpko')
            ->whereDate('trade_date', '2026-07-30')
            ->first();

        $this->assertNotNull($cpkoHistory);
        $this->assertEquals(8123.45, (float) $cpkoHistory->price);
        $this->assertEquals(
            0,
            MpobPriceHistory::query()
                ->where('category', 'cpo')
                ->whereDate('trade_date', '2026-07-30')
                ->count()
        );
        $this->assertEquals(
            0,
            MpobPriceHistory::query()
                ->where('category', 'pk')
                ->whereDate('trade_date', '2026-07-30')
                ->count()
        );
    }

    private function parsedPayload(float $cpo, float $pk, float $cpko, string $tradeDate): array
    {
        return [
            'products' => [
                'cpo' => ['name' => 'Crude Palm Oil (CPO)', 'price' => $cpo, 'price_date' => $tradeDate],
                'pk' => ['name' => 'Palm Kernel (PK)', 'price' => $pk, 'price_date' => $tradeDate],
                'cpko' => ['name' => 'Crude Palm Kernel Oil (CPKO)', 'price' => $cpko, 'price_date' => $tradeDate],
            ],
            'mpob_last_update' => '30/07/2026 5.00 PM',
            'source_url' => MpobPriceService::SOURCE_URL,
            'refreshed_at' => null,
        ];
    }
}
