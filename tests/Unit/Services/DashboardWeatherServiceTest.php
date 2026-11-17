<?php

namespace Tests\Unit\Services;

use App\Services\DashboardWeatherService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardWeatherServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Cache::forget('dashboard.weather.KHG');
    }

    public function test_it_returns_open_meteo_weather_and_caches_the_result(): void
    {
        Config::set('weather.mills.KHG', ['latitude' => 1.23, 'longitude' => 103.45]);
        Http::fake([
            'api.open-meteo.com/*' => Http::response([
                'current' => [
                    'temperature_2m' => 31.7,
                    'weather_code' => 61,
                    'time' => '2026-11-04T09:00',
                ],
            ]),
        ]);

        $service = $this->app->make(DashboardWeatherService::class);
        $first = $service->forMill('KHG');
        $second = $service->forMill('KHG');

        $this->assertTrue($first['available']);
        $this->assertSame(31.7, $first['temperature']);
        $this->assertSame('Hujan', $first['condition']);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_it_does_not_make_an_api_request_without_verified_coordinates(): void
    {
        Config::set('weather.mills.KHG', ['latitude' => null, 'longitude' => null]);
        Http::fake();

        $weather = $this->app->make(DashboardWeatherService::class)->forMill('KHG');

        $this->assertFalse($weather['available']);
        $this->assertSame('Koordinat lokasi kilang belum disahkan.', $weather['message']);
        Http::assertNothingSent();
    }
}
