<?php

namespace Tests\Feature;

use App\Models\KpiIndicatorSetting;
use App\Models\KpiTarget;
use App\Models\Mill;
use App\Services\KpiEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    private KpiEvaluationService $service;
    private Mill $kbb;
    private Mill $kkhg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(KpiEvaluationService::class);
        $this->kbb = Mill::create([
            'name' => 'Kilang Sawit Bukit Bujang',
            'code' => 'BBJ',
            'location' => 'Segamat, Johor',
            'is_active' => true,
        ]);
        $this->kkhg = Mill::create([
            'name' => 'Kilang Sawit PPNJ Kahang',
            'code' => 'KHG',
            'location' => 'Kluang, Johor',
            'is_active' => true,
        ]);
    }

    public function test_bts_thresholds_are_stored_and_evaluated_in_mt(): void
    {
        $this->createFlowSetting('bts_diproses', $this->kbb->id, 2026, [
            '7' => ['green' => 10800, 'red' => 8640],
        ]);

        $setting = KpiIndicatorSetting::firstOrFail();
        $this->assertSame(10800.0, (float) $setting->monthly_targets['7']['green']);
        $this->assertSame(8640.0, (float) $setting->monthly_targets['7']['red']);

        $result = $this->service->evaluate('bts_diproses', 9000.0, $this->kbb->id, 2026, 7, true);
        $this->assertSame('yellow', $result['status']);
        $this->assertSame('MT', $result['unit']);
    }

    public function test_4850_mt_on_16_july_returns_yellow_against_prorated_thresholds(): void
    {
        $this->createFlowSetting('bts_diproses', $this->kbb->id, 2026, [
            '7' => ['green' => 10800, 'red' => 8640],
        ]);

        $result = $this->service->evaluate('bts_diproses', 4850.0, $this->kbb->id, 2026, 7, true, '2026-07-16');

        $this->assertSame(5574.19, $result['green_threshold']);
        $this->assertSame(4459.35, $result['red_threshold']);
        $this->assertSame('yellow', $result['status']);
    }

    public function test_month_end_uses_full_monthly_mt_thresholds(): void
    {
        $this->createFlowSetting('jualan_cpo', $this->kbb->id, 2026, [
            '7' => ['green' => 10800, 'red' => 8640],
        ]);

        $result = $this->service->evaluate('jualan_cpo', 10800.0, $this->kbb->id, 2026, 7, true, '2026-07-31');

        $this->assertSame(10800.0, $result['green_threshold']);
        $this->assertSame(8640.0, $result['red_threshold']);
        $this->assertSame('green', $result['status']);
    }

    public function test_raw_mt_is_not_compared_against_percentage_thresholds(): void
    {
        $this->createFlowSetting('bts_diproses', $this->kbb->id, 2026, [
            '7' => ['green' => 10000, 'red' => 8000],
        ]);

        $result = $this->service->evaluate('bts_diproses', 4850.0, $this->kbb->id, 2026, 7, true);

        $this->assertSame('red', $result['status']);
        $this->assertSame(10000.0, $result['green_threshold']);
        $this->assertSame(8000.0, $result['red_threshold']);
    }

    public function test_cpo_and_pk_production_and_sales_use_mt(): void
    {
        $this->createFlowSetting('pengeluaran_cpo', $this->kbb->id, 2026, [
            '7' => ['green' => 5000, 'red' => 4000],
        ]);
        $this->createFlowSetting('pengeluaran_pk', $this->kbb->id, 2026, [
            '7' => ['green' => 1200, 'red' => 900],
        ]);
        $this->createFlowSetting('jualan_cpo', $this->kbb->id, 2026, [
            '7' => ['green' => 4800, 'red' => 3800],
        ]);
        $this->createFlowSetting('jualan_pk', $this->kbb->id, 2026, [
            '7' => ['green' => 1100, 'red' => 850],
        ]);

        $cpo = $this->service->evaluate('pengeluaran_cpo', 4600.0, $this->kbb->id, 2026, 7, true);
        $pk = $this->service->evaluate('pengeluaran_pk', 920.0, $this->kbb->id, 2026, 7, true);
        $salesCpo = $this->service->evaluate('jualan_cpo', 3900.0, $this->kbb->id, 2026, 7, true);
        $salesPk = $this->service->evaluate('jualan_pk', 840.0, $this->kbb->id, 2026, 7, true);

        $this->assertSame('MT', $cpo['unit']);
        $this->assertSame('yellow', $cpo['status']);
        $this->assertSame('MT', $pk['unit']);
        $this->assertSame('yellow', $pk['status']);
        $this->assertSame('MT', $salesCpo['unit']);
        $this->assertSame('yellow', $salesCpo['status']);
        $this->assertSame('MT', $salesPk['unit']);
        $this->assertSame('red', $salesPk['status']);
    }

    public function test_stock_uses_mt_and_is_not_prorated(): void
    {
        $this->createDirectSetting('stok_cpo', $this->kbb->id, 2026, 500.0, 800.0);

        $result = $this->service->evaluate('stok_cpo', 600.0, $this->kbb->id, 2026, 7, true, '2026-07-16');

        $this->assertSame('MT', $result['unit']);
        $this->assertSame(500.0, $result['green_threshold']);
        $this->assertSame(800.0, $result['red_threshold']);
        $this->assertSame('yellow', $result['status']);
    }

    public function test_downtime_uses_hours_and_lower_is_better(): void
    {
        $this->createDirectSetting('downtime', $this->kbb->id, 2026, 2.0, 5.0);

        $green = $this->service->evaluate('downtime', 1.5, $this->kbb->id, 2026);
        $yellow = $this->service->evaluate('downtime', 3.0, $this->kbb->id, 2026);
        $red = $this->service->evaluate('downtime', 6.0, $this->kbb->id, 2026);

        $this->assertSame('Jam', $green['unit']);
        $this->assertSame('green', $green['status']);
        $this->assertSame('yellow', $yellow['status']);
        $this->assertSame('red', $red['status']);
    }

    public function test_zero_downtime_hours_returns_green(): void
    {
        $this->createDirectSetting('downtime', $this->kbb->id, 2026, 2.0, 5.0);

        $result = $this->service->evaluate('downtime', 0.0, $this->kbb->id, 2026, null, true);

        $this->assertSame('green', $result['status']);
    }

    public function test_oer_and_ker_remain_percentage_based(): void
    {
        $this->createDirectSetting('oer', $this->kbb->id, 2026, 21.0, 19.0);
        $this->createDirectSetting('ker', $this->kbb->id, 2026, 6.0, 4.0);

        $oer = $this->service->evaluate('oer', 20.0, $this->kbb->id, 2026);
        $ker = $this->service->evaluate('ker', 6.2, $this->kbb->id, 2026);

        $this->assertSame('%', $oer['unit']);
        $this->assertSame('yellow', $oer['status']);
        $this->assertSame('%', $ker['unit']);
        $this->assertSame('green', $ker['status']);
    }

    public function test_sales_vs_production_remains_percentage_based(): void
    {
        $this->createDirectSetting('jualan_cpo_vs_pengeluaran_cpo', $this->kbb->id, 2026, 100.0, 80.0);
        $this->createDirectSetting('jualan_pk_vs_pengeluaran_pk', $this->kbb->id, 2026, 100.0, 80.0);

        $cpo = $this->service->evaluate('jualan_cpo_vs_pengeluaran_cpo', 92.0, $this->kbb->id, 2026);
        $pk = $this->service->evaluate('jualan_pk_vs_pengeluaran_pk', 70.0, $this->kbb->id, 2026);

        $this->assertSame('%', $cpo['unit']);
        $this->assertSame('yellow', $cpo['status']);
        $this->assertSame('%', $pk['unit']);
        $this->assertSame('red', $pk['status']);
    }

    public function test_january_to_june_2026_returns_grey_not_applicable(): void
    {
        $this->createFlowSetting('bts_diproses', $this->kbb->id, 2026, [
            '7' => ['green' => 10800, 'red' => 8640],
        ]);

        $result = $this->service->evaluate('bts_diproses', 4500.0, $this->kbb->id, 2026, 3, true, '2026-03-16');

        $this->assertSame('grey', $result['status']);
        $this->assertSame('Tidak Berkenaan', $result['status_label']);
        $this->assertSame('not_applicable', $result['target_type']);
    }

    public function test_missing_monthly_green_or_red_returns_grey(): void
    {
        $this->createFlowSetting('pengeluaran_cpo', $this->kbb->id, 2026, [
            '7' => ['green' => 5000, 'red' => null],
        ]);

        $result = $this->service->evaluate('pengeluaran_cpo', 4500.0, $this->kbb->id, 2026, 7, true);

        $this->assertSame('grey', $result['status']);
        $this->assertSame('Belum Ditetapkan', $result['status_label']);
    }

    public function test_mill_specific_setting_resolves_correctly(): void
    {
        $this->createFlowSetting('bts_diproses', $this->kbb->id, 2026, [
            '7' => ['green' => 14000, 'red' => 11200],
        ]);

        $result = $this->service->evaluate('bts_diproses', 10000.0, $this->kbb->id, 2026, 7, true);

        $this->assertSame('mill', $result['target_source']);
        $this->assertSame(14000.0, $result['green_threshold']);
        $this->assertSame('red', $result['status']);
    }

    public function test_missing_mill_setting_returns_grey_without_fallback(): void
    {
        $this->createFlowSetting('bts_diproses', $this->kbb->id, 2026, [
            '7' => ['green' => 10800, 'red' => 8640],
        ]);

        $result = $this->service->evaluate('bts_diproses', 9000.0, $this->kkhg->id, 2026, 7, true);

        $this->assertSame('grey', $result['status']);
        $this->assertSame('Belum Ditetapkan', $result['status_label']);
    }

    public function test_global_setting_is_not_used_as_fallback(): void
    {
        $this->createFlowSetting('bts_diproses', null, 2026, [
            '7' => ['green' => 10800, 'red' => 8640],
        ]);

        $result = $this->service->evaluate('bts_diproses', 9000.0, $this->kbb->id, 2026, 7, true);

        $this->assertSame('grey', $result['status']);
        $this->assertSame('Belum Ditetapkan', $result['status_label']);
    }

    public function test_kbb_and_kkhg_values_remain_independent(): void
    {
        $this->createFlowSetting('bts_diproses', $this->kbb->id, 2026, [
            '7' => ['green' => 14000, 'red' => 11200],
        ]);
        $this->createFlowSetting('bts_diproses', $this->kkhg->id, 2026, [
            '7' => ['green' => 10800, 'red' => 8640],
        ]);

        $kbbResult = $this->service->evaluate('bts_diproses', 10000.0, $this->kbb->id, 2026, 7, true);
        $kkhgResult = $this->service->evaluate('bts_diproses', 10000.0, $this->kkhg->id, 2026, 7, true);

        $this->assertSame('red', $kbbResult['status']);
        $this->assertSame('yellow', $kkhgResult['status']);
    }

    public function test_existing_legacy_kpi_and_ffa_records_remain_valid(): void
    {
        $legacy = KpiTarget::create([
            'mill_id' => null,
            'oer_target' => 20.0,
            'ker_target' => 5.0,
            'ffa_max' => 5.0,
            'downtime_max_hours' => 2.0,
            'effective_year' => 2026,
            'is_active' => true,
        ]);

        $this->createFlowSetting('bts_diproses', $this->kbb->id, 2026, [
            '7' => ['green' => 10800, 'red' => 8640],
        ]);

        $legacy->refresh();

        $this->assertSame(5.0, (float) $legacy->ffa_max);
        $this->assertSame(20.0, (float) $legacy->oer_target);
        $this->assertSame(5.0, (float) $legacy->ker_target);
    }

    private function createFlowSetting(string $indicatorCode, ?int $millId, int $year, array $monthlyThresholds): KpiIndicatorSetting
    {
        $indicator = KpiEvaluationService::indicatorMap()[$indicatorCode];

        return KpiIndicatorSetting::create([
            'mill_id' => $millId,
            'year' => $year,
            'indicator_code' => $indicatorCode,
            'indicator_name' => $indicator['name'],
            'unit' => $indicator['unit'],
            'evaluation_direction' => $indicator['direction'],
            'green_threshold' => null,
            'red_threshold' => null,
            'period_target' => null,
            'monthly_targets' => $monthlyThresholds,
            'is_active' => true,
        ]);
    }

    private function createDirectSetting(string $indicatorCode, ?int $millId, int $year, float $green, float $red): KpiIndicatorSetting
    {
        $indicator = KpiEvaluationService::indicatorMap()[$indicatorCode];

        return KpiIndicatorSetting::create([
            'mill_id' => $millId,
            'year' => $year,
            'indicator_code' => $indicatorCode,
            'indicator_name' => $indicator['name'],
            'unit' => $indicator['unit'],
            'evaluation_direction' => $indicator['direction'],
            'green_threshold' => $green,
            'red_threshold' => $red,
            'period_target' => null,
            'monthly_targets' => [],
            'is_active' => true,
        ]);
    }
}
