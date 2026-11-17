<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DashboardWeatherService
{
    private const MILL_CODES = ['KHG', 'BBJ'];

    public function forMill(string $millCode): array
    {
        $millCode = strtoupper($millCode);
        $locations = config('weather.mills', []);
        $location = $locations[$millCode] ?? null;

        if (! in_array($millCode, self::MILL_CODES, true) || ! is_array($location)) {
            return $this->unavailable($millCode);
        }

        $latitude = $location['latitude'] ?? null;
        $longitude = $location['longitude'] ?? null;
        if (! is_numeric($latitude)
            || ! is_numeric($longitude)
            || (float) $latitude < -90
            || (float) $latitude > 90
            || (float) $longitude < -180
            || (float) $longitude > 180) {
            return $this->unavailable($millCode);
        }

        $cacheKey = 'dashboard.weather.' . $millCode;

        return Cache::remember($cacheKey, now()->addMinutes(20), function () use (
            $millCode,
            $latitude,
            $longitude
        ): array {
            try {
                $response = Http::acceptJson()
                    ->connectTimeout(1)
                    ->timeout(3)
                    ->get('https://api.open-meteo.com/v1/forecast', [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'current' => 'temperature_2m,weather_code',
                        'timezone' => 'Asia/Kuala_Lumpur',
                    ]);

                if (! $response->successful()) {
                    throw new \RuntimeException('Open-Meteo returned HTTP ' . $response->status() . '.');
                }

                $current = $response->json('current');
                $temperature = data_get($current, 'temperature_2m');
                $weatherCode = data_get($current, 'weather_code');

                if (! is_numeric($temperature) || ! is_numeric($weatherCode)) {
                    throw new \RuntimeException('Open-Meteo response did not contain valid current weather values.');
                }

                [$condition, $icon] = $this->describeWeather((int) $weatherCode);

                return [
                    'mill_code' => $millCode,
                    'available' => true,
                    'temperature' => round((float) $temperature, 1),
                    'condition' => $condition,
                    'icon' => $icon,
                    'updated_at' => data_get($current, 'time'),
                    'source' => 'Open-Meteo',
                ];
            } catch (\Throwable $exception) {
                Log::warning('Dashboard weather lookup failed.', [
                    'mill_code' => $millCode,
                    'message' => $exception->getMessage(),
                ]);

                return $this->unavailable($millCode);
            }
        });
    }

    private function unavailable(string $millCode): array
    {
        $location = config('weather.mills.' . $millCode, []);
        $hasCoordinates = is_numeric($location['latitude'] ?? null)
            && is_numeric($location['longitude'] ?? null);

        return [
            'mill_code' => $millCode,
            'available' => false,
            'message' => $hasCoordinates
                ? 'Cuaca semasa tidak tersedia.'
                : 'Koordinat lokasi kilang belum disahkan.',
            'source' => null,
        ];
    }

    private function describeWeather(int $code): array
    {
        return match (true) {
            $code === 0 => ['Cerah', '☀️'],
            in_array($code, [1, 2], true) => ['Cerah berawan', '🌤️'],
            $code === 3 => ['Mendung', '☁️'],
            in_array($code, [45, 48], true) => ['Berkabus', '🌫️'],
            in_array($code, [51, 53, 55, 56, 57], true) => ['Hujan renyai', '🌦️'],
            in_array($code, [61, 63, 65, 66, 67, 80, 81, 82], true) => ['Hujan', '🌧️'],
            in_array($code, [71, 73, 75, 77, 85, 86], true) => ['Salji', '❄️'],
            in_array($code, [95, 96, 99], true) => ['Ribut petir', '⛈️'],
            default => ['Keadaan cuaca tidak diketahui', '🌡️'],
        };
    }
}
