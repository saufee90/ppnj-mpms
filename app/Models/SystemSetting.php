<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public const CACHE_KEY = 'system_settings.cached_values';

    public const DEFAULTS = [
        'maintenance_enabled' => false,
        'maintenance_type' => 'system_update',
        'maintenance_title' => 'Sistem Sedang Diselenggara',
        'maintenance_message' => 'Sistem MPS sedang menjalani kerja-kerja penyelenggaraan dan penambahbaikan bagi meningkatkan prestasi serta kestabilan sistem. Segala kesulitan amat dikesali.',
        'maintenance_notes' => null,
        'maintenance_end_at' => null,
        'system_version' => '1.0.5',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $values = static::cachedValues();

        if (! array_key_exists($key, $values)) {
            return $default;
        }

        return $values[$key];
    }

    public static function setValue(string $key, mixed $value): self
    {
        $storedValue = static::normalizeValue($key, $value);

        $setting = static::updateOrCreate(
            ['key' => $key],
            ['value' => $storedValue]
        );

        static::forgetCache();

        return $setting;
    }

    public static function maintenanceEnabled(): bool
    {
        return filter_var(static::getValue('maintenance_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function systemVersion(): string
    {
        return (string) static::getValue('system_version', self::DEFAULTS['system_version']);
    }

    public static function cachedValues(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            return static::query()->pluck('value', 'key')->all();
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function mergedValues(array $overrides = []): array
    {
        return array_replace(self::DEFAULTS, static::cachedValues(), static::normalizeOverrideValues($overrides));
    }

    public static function maintenanceLabel(string $type): string
    {
        return match ($type) {
            'scheduled_maintenance' => 'Penyelenggaraan Berkala',
            'system_update' => 'Kemaskini Sistem',
            'deployment' => 'Deployment Versi Baharu',
            'recalculation' => 'Pengiraan Semula Data',
            'emergency' => 'Penyelenggaraan Kecemasan',
            default => 'Kemaskini Sistem',
        };
    }

    public static function retryAfterSeconds(mixed $maintenanceEndAt): ?int
    {
        if (! is_string($maintenanceEndAt) || trim($maintenanceEndAt) === '') {
            return null;
        }

        try {
            $seconds = now(config('app.timezone'))->diffInSeconds(Carbon::parse($maintenanceEndAt, config('app.timezone')), false);

            return $seconds > 0 ? $seconds : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function maintenanceOptions(): array
    {
        return [
            'scheduled_maintenance' => 'Penyelenggaraan Berkala',
            'system_update' => 'Kemaskini Sistem',
            'deployment' => 'Deployment Versi Baharu',
            'recalculation' => 'Pengiraan Semula Data',
            'emergency' => 'Penyelenggaraan Kecemasan',
        ];
    }

    private static function normalizeOverrideValues(array $overrides): array
    {
        $normalized = [];

        foreach ($overrides as $key => $value) {
            $normalized[$key] = static::normalizePublicValue($key, $value);
        }

        return $normalized;
    }

    private static function normalizeValue(string $key, mixed $value): string|null
    {
        $publicValue = static::normalizePublicValue($key, $value);

        if ($publicValue === null) {
            return null;
        }

        if ($key === 'maintenance_enabled') {
            return $publicValue ? '1' : '0';
        }

        return (string) $publicValue;
    }

    private static function normalizePublicValue(string $key, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($key === 'maintenance_enabled') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if ($key === 'maintenance_end_at') {
            if ($value instanceof Carbon) {
                return $value->toDateTimeString();
            }

            return (string) $value;
        }

        return $value;
    }
}
