<?php

namespace Tests\Feature;

use App\Models\KpiIndicatorSetting;
use App\Models\Mill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizeLegacyBtsIndicatorSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_normalizes_legacy_code_and_preserves_all_setting_values_idempotently(): void
    {
        $mill = $this->createMill('KHG');
        $legacy = $this->createSetting($mill, 'total_bts_diterima', 'Total BTS Diterima', [
            '8' => ['green' => 20138.50, 'red' => 18124.65],
        ], 108000.25, false);

        $this->migration()->up();
        $this->migration()->up();

        $normalized = KpiIndicatorSetting::findOrFail($legacy->id);

        $this->assertSame('bts_diterima_dan_diproses', $normalized->indicator_code);
        $this->assertSame('Penerimaan & Pemprosesan BTS', $normalized->indicator_name);
        $this->assertSame($mill->id, $normalized->mill_id);
        $this->assertSame(2026, $normalized->year);
        $this->assertSame(108000.25, $normalized->period_target);
        $this->assertSame(20138.50, (float) $normalized->monthly_targets['8']['green']);
        $this->assertSame(18124.65, (float) $normalized->monthly_targets['8']['red']);
        $this->assertFalse($normalized->is_active);
        $this->assertSame(1, KpiIndicatorSetting::whereKey($legacy->id)->count());
    }

    public function test_migration_does_not_overwrite_or_duplicate_an_existing_new_setting(): void
    {
        $mill = $this->createMill('KHG');
        $legacy = $this->createSetting($mill, 'total_bts_diterima', 'Total BTS Diterima', [
            '8' => ['green' => 100, 'red' => 80],
        ], null, true);
        $current = $this->createSetting($mill, 'bts_diterima_dan_diproses', 'Penerimaan & Pemprosesan BTS', [
            '8' => ['green' => 20138.50, 'red' => 18124.65],
        ], null, true);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('total_bts_diterima', $legacy->fresh()->indicator_code);
        $this->assertSame(100.0, (float) $legacy->fresh()->monthly_targets['8']['green']);
        $this->assertSame('bts_diterima_dan_diproses', $current->fresh()->indicator_code);
        $this->assertSame(20138.50, (float) $current->fresh()->monthly_targets['8']['green']);
        $this->assertSame(2, KpiIndicatorSetting::where('mill_id', $mill->id)->where('year', 2026)->count());
        $this->assertSame(1, KpiIndicatorSetting::where('indicator_code', 'bts_diterima_dan_diproses')->count());
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_08_10_000001_normalize_legacy_bts_indicator_settings.php');
    }

    private function createMill(string $code): Mill
    {
        return Mill::create([
            'name' => 'Kilang Sawit PPNJ Kahang',
            'code' => $code,
            'location' => 'Kluang, Johor',
            'is_active' => true,
        ]);
    }

    private function createSetting(
        Mill $mill,
        string $indicatorCode,
        string $indicatorName,
        array $monthlyTargets,
        ?float $periodTarget,
        bool $isActive
    ): KpiIndicatorSetting {
        return KpiIndicatorSetting::create([
            'mill_id' => $mill->id,
            'year' => 2026,
            'indicator_code' => $indicatorCode,
            'indicator_name' => $indicatorName,
            'unit' => 'MT',
            'evaluation_direction' => 'higher_is_better',
            'green_threshold' => null,
            'red_threshold' => null,
            'period_target' => $periodTarget,
            'monthly_targets' => $monthlyTargets,
            'is_active' => $isActive,
        ]);
    }
}