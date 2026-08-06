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

    public function test_bts_progress_prorates_early_mid_and_end_month_and_leap_february(): void
    {
        $service = app(KpiEvaluationService::class);
        $this->createFlowSetting($this->kkhg, ['8' => ['green' => 3100, 'red' => 2500]]);
        $this->createFlowSetting($this->kbb, ['2' => ['green' => 2900, 'red' => 2400]], 2028);

        $early = $service->evaluateBtsProgress(95, $this->kkhg->id, '2026-08-01');
        $mid = $service->evaluateBtsProgress(1425, $this->kkhg->id, '2026-08-15');
        $end = $service->evaluateBtsProgress(2945, $this->kkhg->id, '2026-08-31');
        $leap = $service->evaluateBtsProgress(2755, $this->kbb->id, '2028-02-29');

        $this->assertSame(100.0, $early['prorated_target']);
        $this->assertSame(1500.0, $mid['prorated_target']);
        $this->assertSame(3100.0, $end['prorated_target']);
        $this->assertSame(2900.0, $leap['prorated_target']);
        $this->assertSame(29, $leap['elapsed_days']);
        $this->assertSame(29, $leap['total_days']);
    }

    public function test_bts_progress_handles_status_boundaries_and_unavailable_targets(): void
    {
        $service = app(KpiEvaluationService::class);
        $this->createFlowSetting($this->kkhg, ['8' => ['green' => 3100, 'red' => 2500]]);
        $zeroTargetMill = $this->createMill('Kilang Sasaran Sifar', 'ZERO');
        $this->createFlowSetting($zeroTargetMill, ['8' => ['green' => 0, 'red' => 0]]);

        $attention = $service->evaluateBtsProgress(2635, $this->kkhg->id, '2026-08-31');
        $onTarget = $service->evaluateBtsProgress(2945, $this->kkhg->id, '2026-08-31');
        $behind = $service->evaluateBtsProgress(2634.99, $this->kkhg->id, '2026-08-31');
        $zero = $service->evaluateBtsProgress(100, $zeroTargetMill->id, '2026-08-31');
        $missing = $service->evaluateBtsProgress(100, 999999, '2026-08-31');
        $noData = $service->evaluateBtsProgress(null, $this->kkhg->id, '2026-08-31', false);

        $this->assertSame(85.0, $attention['achievement_percentage']);
        $this->assertSame('Perlu Perhatian', $attention['status_label']);
        $this->assertSame(95.0, $onTarget['achievement_percentage']);
        $this->assertSame('Mengikut Sasaran', $onTarget['status_label']);
        $this->assertSame('Ketinggalan', $behind['status_label']);
        $this->assertSame('Tidak Dinilai', $zero['status_label']);
        $this->assertSame('Tidak Dinilai', $missing['status_label']);
        $this->assertSame('Tidak Dinilai', $noData['status_label']);
        $this->assertNull($zero['achievement_percentage']);
    }

    public function test_combined_bts_progress_uses_pooled_actual_and_target_totals(): void
    {
        $service = app(KpiEvaluationService::class);
        $this->createFlowSetting($this->kkhg, ['8' => ['green' => 3100, 'red' => 2500]]);
        $this->createFlowSetting($this->kbb, ['8' => ['green' => 6200, 'red' => 5000]]);

        $combined = $service->combineBtsProgress([
            $service->evaluateBtsProgress(2500, $this->kkhg->id, '2026-08-31'),
            $service->evaluateBtsProgress(4000, $this->kbb->id, '2026-08-31'),
        ], '2026-08-31');

        $this->assertSame(6500.0, $combined['actual_bts_mtd']);
        $this->assertSame(9300.0, $combined['prorated_target']);
        $this->assertSame(69.89, $combined['achievement_percentage']);
        $this->assertSame(-2800.0, $combined['variance']);
    }

    public function test_pdf_filters_khg_kbb_combined_and_invalid_scope_and_respects_as_of_date(): void
    {
        $reportDate = Carbon::parse('2026-08-05');
        $this->createOperation($this->kkhg, ['tarikh' => '2026-08-05', 'bts_diterima' => 500]);
        $this->createOperation($this->kbb, ['tarikh' => '2026-08-05', 'bts_diterima' => 700]);
        $this->createOperation($this->kkhg, ['tarikh' => '2026-08-06', 'bts_diterima' => 900]);
        $service = app(DashboardPdfService::class);

        $khg = $service->generate($reportDate, 'KHG');
        $kbb = $service->generate($reportDate, 'BBJ');
        $combined = $service->generate($reportDate);
        $invalid = $service->generate($reportDate, 'NOT-A-MILL');
        $khgHtml = $this->renderReport($khg['mill_summaries']);
        $kbbHtml = $this->renderReport($kbb['mill_summaries']);
        $combinedHtml = $this->renderReport($combined['mill_summaries']);

        $this->assertSame(['KHG'], $khg['mill_summaries']->pluck('code')->all());
        $this->assertSame(500.0, $khg['mill_summaries']->first()['mtd']['bts_diterima']);
        $this->assertSame(['BBJ'], $kbb['mill_summaries']->pluck('code')->all());
        $this->assertSame(['KHG', 'BBJ'], $combined['mill_summaries']->pluck('code')->all());
        $this->assertSame(['KHG', 'BBJ'], $invalid['mill_summaries']->pluck('code')->all());
        $this->assertStringStartsWith('%PDF', $khg['content']);
        $this->assertStringStartsWith('%PDF', $kbb['content']);
        $this->assertMtdTableColumnCount($khgHtml, 2);
        $this->assertMtdTableColumnCount($kbbHtml, 2);
        $this->assertMtdTableColumnCount($combinedHtml, 3);
    }

    public function test_dashboard_pdf_url_keeps_date_and_mill_filters_together(): void
    {
        $url = route('dashboard.pdf', ['tarikh' => '2026-08-05', 'mill' => 'KHG']);

        $this->assertStringContainsString('tarikh=2026-08-05', $url);
        $this->assertStringContainsString('mill=KHG', $url);
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

    private function createFlowSetting(Mill $mill, array $monthlyTargets, int $year = 2026): KpiIndicatorSetting
    {
        $indicator = KpiEvaluationService::indicatorMap()['bts_diterima_dan_diproses'];

        return KpiIndicatorSetting::create([
            'mill_id' => $mill->id,
            'year' => $year,
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

    private function assertMtdTableColumnCount(string $html, int $expectedColumns): void
    {
        $document = new \DOMDocument();
        $previousErrorState = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorState);

        $xpath = new \DOMXPath($document);
        $rows = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " mtd-table ")]//tr');

        $this->assertNotFalse($rows);
        $this->assertGreaterThan(0, $rows->length);

        foreach ($rows as $row) {
            $cells = $xpath->query('./th|./td', $row);
            $label = trim($cells->item(0)?->textContent ?? 'baris MTD');

            $this->assertSame($expectedColumns, $cells->length, "Bilangan kolum tidak sepadan untuk {$label}.");
        }
    }
}
