<?php

namespace Tests\Feature;

use App\Models\DailyOperation;
use App\Models\KpiIndicatorSetting;
use App\Models\Mill;
use App\Models\Role;
use App\Models\User;
use App\Services\DashboardPdfService;
use App\Services\KpiEvaluationService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DailyDashboardKpiReportTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $reportDate;
    private Mill $kbb;
    private Mill $kkhg;
    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reportDate = Carbon::parse('2026-07-31');

        if (! Schema::hasColumn('daily_operations', 'operation_status')) {
            Schema::table('daily_operations', function (Blueprint $table) {
                $table->string('operation_status')->nullable();
            });
        }

        $role = Role::create(['name' => Role::PEGAWAI_KILANG, 'label' => 'Pegawai Kilang']);
        $this->kbb = $this->createMill('Kilang Sawit Bukit Bujang', 'BBJ');
        $this->kkhg = $this->createMill('Kilang Sawit PPNJ Kahang', 'KHG');
        $this->officer = User::create([
            'name' => 'Pegawai Laporan Harian',
            'email' => 'pegawai-laporan@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $role->id,
            'mill_id' => $this->kbb->id,
            'is_active' => true,
        ]);
    }

    public function test_daily_pdf_is_generated_and_remains_neutral_without_kpi_settings(): void
    {
        $operation = $this->createOperation($this->kbb, [
            'bts_diterima' => 1100,
            'bts_diproses' => 900,
            'jam_operasi' => 40,
            'downtime_jam' => 2,
        ]);
        $this->createOperation($this->kkhg, [
            'bts_diterima' => 700,
            'bts_diproses' => 650,
            'jam_operasi' => 30,
            'downtime_jam' => 1,
        ]);

        $report = app(DashboardPdfService::class)->generate($this->reportDate);
        $summary = $report['mill_summaries']->firstWhere('mill_id', $this->kbb->id);
        $html = $this->renderReport($report['mill_summaries']);

        $this->assertStringStartsWith('%PDF', $report['content']);
        $this->assertNotEmpty($report['content']);
        $this->assertSame(1100.0, $summary['kpi']['bts']['actual_bts_diterima']);
        $this->assertSame(900.0, $summary['kpi']['bts']['actual_bts_diproses']);
        $this->assertNull($summary['kpi']['bts']['target']);
        $this->assertSame('grey', $summary['kpi']['bts']['status']);
        $this->assertSame('Belum Ditetapkan', $summary['kpi']['bts']['status_label']);
        $this->assertSame(5.0, $summary['kpi']['downtime']['actual_percentage']);
        $this->assertNull($summary['kpi']['downtime']['green_threshold']);
        $this->assertSame('grey', $summary['kpi']['downtime']['status']);
        $this->assertStringContainsString('KPI Belum Ditetapkan', $html);

        $operation->refresh();
        $this->assertSame(1100.0, (float) $operation->bts_diterima);
        $this->assertSame(900.0, (float) $operation->bts_diproses);
        $this->assertSame(40.0, (float) $operation->jam_operasi);
        $this->assertSame(2.0, (float) $operation->downtime_jam);
    }

    public function test_daily_report_uses_service_targets_variances_and_statuses(): void
    {
        $operation = $this->createOperation($this->kbb, [
            'bts_diterima' => 1100,
            'bts_diproses' => 900,
            'jam_operasi' => 40,
            'downtime_jam' => 3,
        ]);
        $this->createOperation($this->kkhg);
        $this->createFlowSetting($this->kbb, ['7' => ['green' => 1000, 'red' => 800]]);
        $this->createDirectSetting($this->kbb, 3, 6);

        $report = app(DashboardPdfService::class)->generate($this->reportDate);
        $summary = $report['mill_summaries']->firstWhere('mill_id', $this->kbb->id);
        $html = $this->renderReport($report['mill_summaries']);
        $evaluationService = app(KpiEvaluationService::class);
        $expectedBts = $evaluationService->evaluateBtsCombined(
            1100,
            900,
            $this->kbb->id,
            2026,
            7,
            true,
            '2026-07-31'
        );
        $expectedDowntime = $evaluationService->evaluateDowntimeFromRows(
            collect([$operation]),
            $this->kbb->id,
            2026,
            7,
            true,
            '2026-07-31'
        );

        $this->assertSame($expectedBts, $summary['kpi']['bts']);
        $this->assertSame($expectedDowntime, $summary['kpi']['downtime']);
        $this->assertSame(1000.0, $summary['kpi']['bts']['target']);
        $this->assertSame('green', $summary['kpi']['bts']['received_result']['status']);
        $this->assertSame('yellow', $summary['kpi']['bts']['processed_result']['status']);
        $this->assertSame('yellow', $summary['kpi']['bts']['status']);
        $this->assertSame(7.5, $summary['kpi']['downtime']['actual_percentage']);
        $this->assertSame(3.0, $summary['kpi']['downtime']['green_threshold']);
        $this->assertSame(4.5, $summary['kpi']['downtime']['variance']);
        $this->assertSame('red', $summary['kpi']['downtime']['status']);
        $this->assertStringContainsString('style="color: ' . $expectedBts['colour'] . ';"', $html);
        $this->assertStringContainsString('style="color: ' . $expectedDowntime['colour'] . ';"', $html);
    }

    public function test_zero_operating_hours_is_not_applicable_in_daily_report(): void
    {
        $this->createOperation($this->kbb, [
            'bts_diterima' => 100,
            'bts_diproses' => 0,
            'jam_operasi' => 0,
            'downtime_jam' => 2,
            'operation_status' => 'Tidak Operasi',
        ]);
        $this->createOperation($this->kkhg);
        $this->createDirectSetting($this->kbb, 3, 6);

        $report = app(DashboardPdfService::class)->generate($this->reportDate);
        $summary = $report['mill_summaries']->firstWhere('mill_id', $this->kbb->id);
        $html = $this->renderReport($report['mill_summaries']);

        $this->assertNull($summary['kpi']['downtime']['actual_percentage']);
        $this->assertNull($summary['kpi']['downtime']['variance']);
        $this->assertSame('grey', $summary['kpi']['downtime']['status']);
        $this->assertSame('Tidak Berkenaan', $summary['kpi']['downtime']['status_label']);
        $this->assertStringContainsString('Tidak Berkenaan', $html);
        $this->assertStringContainsString('<th>Downtime actual</th><td class="value">Tidak Berkenaan</td>', $html);
        $this->assertStringNotContainsString('<th>Downtime actual</th><td class="value">0.00%</td>', $html);
    }

    private function createMill(string $name, string $code): Mill
    {
        return Mill::create([
            'name' => $name,
            'code' => $code,
            'location' => 'Johor',
            'is_active' => true,
        ]);
    }

    private function createOperation(Mill $mill, array $overrides = []): DailyOperation
    {
        $payload = array_merge([
            'tarikh' => $this->reportDate->toDateString(),
            'mill_id' => $mill->id,
            'shift' => 'Harian',
            'officer_id' => $this->officer->id,
            'operation_status' => 'Operasi',
            'bts_diterima' => 0,
            'bts_diproses' => 0,
            'baki_bts_semalam' => 0,
            'baki_bts_selepas_diproses' => 0,
            'jam_operasi' => 0,
            'downtime_jam' => 0,
            'sebab_downtime' => null,
            'pengeluaran_cpo' => 0,
            'pengeluaran_pk' => 0,
            'pk_kcp_to_hopper' => 0,
            'produksi_cpo' => 0,
            'produksi_pk' => 0,
            'stok_cpo' => 0,
            'stok_pk' => 0,
            'stok_cpo_yesterday' => 0,
            'stok_pk_yesterday' => 0,
            'ffa' => 0,
            'moisture' => 0,
            'dirt' => 0,
            'throughput' => 0,
            'utilisation_rate' => 0,
            'status' => 'submitted',
        ], $overrides);

        return DailyOperation::create($payload);
    }

    private function createFlowSetting(Mill $mill, array $monthlyTargets): KpiIndicatorSetting
    {
        $indicator = KpiEvaluationService::indicatorMap()['bts_diterima_dan_diproses'];

        return KpiIndicatorSetting::create([
            'mill_id' => $mill->id,
            'year' => 2026,
            'indicator_code' => 'bts_diterima_dan_diproses',
            'indicator_name' => $indicator['name'],
            'unit' => $indicator['unit'],
            'evaluation_direction' => $indicator['direction'],
            'monthly_targets' => $monthlyTargets,
            'is_active' => true,
        ]);
    }

    private function createDirectSetting(Mill $mill, float $green, float $red): KpiIndicatorSetting
    {
        $indicator = KpiEvaluationService::indicatorMap()['downtime'];

        return KpiIndicatorSetting::create([
            'mill_id' => $mill->id,
            'year' => 2026,
            'indicator_code' => 'downtime',
            'indicator_name' => $indicator['name'],
            'unit' => $indicator['unit'],
            'evaluation_direction' => $indicator['direction'],
            'green_threshold' => $green,
            'red_threshold' => $red,
            'monthly_targets' => [],
            'is_active' => true,
        ]);
    }

    private function renderReport($millSummaries): string
    {
        return view('dashboard.pdf', [
            'logoDataUri' => null,
            'displayDateText' => $this->reportDate->translatedFormat('d F Y'),
            'generatedAtText' => now()->translatedFormat('d F Y, H:i'),
            'millSummaries' => $millSummaries,
            'attentionMessages' => [],
        ])->render();
    }
}
