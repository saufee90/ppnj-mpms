<?php

namespace Tests\Feature;

use App\Models\DailyOperation;
use App\Models\KpiIndicatorSetting;
use App\Models\Mill;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiEvaluationService;
use App\Services\ManagementMonthlyReportPresentationService;
use App\Services\ManagementMonthlyReportService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManagementMonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    private ManagementMonthlyReportService $service;
    private Mill $kbb;
    private Mill $kahang;
    private User $admin;
    private User $pengurusan;
    private User $pegawaiKbb;
    private User $pengurusKahang;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('daily_operations', 'operation_status')) {
            Schema::table('daily_operations', function (Blueprint $table) {
                $table->string('operation_status')->nullable();
            });
        }

        $adminRole = Role::create(['name' => Role::ADMIN, 'label' => 'Admin']);
        $managementRole = Role::create(['name' => Role::PENGURUSAN, 'label' => 'Pengurusan']);
        $officerRole = Role::create(['name' => Role::PEGAWAI_KILANG, 'label' => 'Pegawai Kilang']);
        $managerRole = Role::create(['name' => Role::PENGURUS_KILANG, 'label' => 'Pengurus Kilang']);

        $this->kbb = $this->createMill('Kilang Sawit Bukit Bujang', 'BBJ');
        $this->kahang = $this->createMill('Kilang Sawit PPNJ Kahang', 'KHG');
        $this->admin = $this->createUser('admin-monthly@test.local', $adminRole);
        $this->pengurusan = $this->createUser('management-monthly@test.local', $managementRole);
        $this->pegawaiKbb = $this->createUser('officer-monthly@test.local', $officerRole, $this->kbb);
        $this->pengurusKahang = $this->createUser('manager-monthly@test.local', $managerRole, $this->kahang);
        $this->service = app(ManagementMonthlyReportService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_july_all_mills_builds_overall_per_mill_and_comparison_data(): void
    {
        $this->seedMonthlyOperations();

        $dataset = $this->service->generate($this->admin, 2026, 7);

        $this->assertSame('all_mills', $dataset['meta']['scope_type']);
        $this->assertSame('Semua Kilang', $dataset['meta']['scope_label']);
        $this->assertCount(2, $dataset['mills']);
        $this->assertTrue($dataset['flags']['showMillComparison']);
        $this->assertCount(2, $dataset['comparison']);
        $this->assertSame(2100.0, $dataset['overall']['metrics']['bts_diterima']);
    }

    public function test_july_kahang_scope_contains_only_kahang_and_no_comparison(): void
    {
        $this->seedMonthlyOperations();

        $dataset = $this->service->generate($this->admin, 2026, 7, $this->kahang->id);

        $this->assertSame('single_mill', $dataset['meta']['scope_type']);
        $this->assertSame($this->kahang->name, $dataset['meta']['scope_label']);
        $this->assertCount(1, $dataset['mills']);
        $this->assertSame('KHG', $dataset['mills'][0]['mill']['code']);
        $this->assertFalse($dataset['flags']['showMillComparison']);
        $this->assertSame([], $dataset['comparison']);
    }

    public function test_july_bukit_bujang_scope_contains_only_bukit_bujang(): void
    {
        $this->seedMonthlyOperations();

        $dataset = $this->service->generate($this->admin, 2026, 7, $this->kbb->id);

        $this->assertCount(1, $dataset['mills']);
        $this->assertSame('BBJ', $dataset['mills'][0]['mill']['code']);
        $this->assertSame(1100.0, $dataset['overall']['metrics']['bts_diterima']);
        $this->assertFalse($dataset['flags']['showMillComparison']);
    }

    public function test_mill_scoped_user_cannot_request_another_mill_or_all_mills(): void
    {
        $this->seedMonthlyOperations();

        $dataset = $this->service->generate($this->pegawaiKbb, 2026, 7, $this->kahang->id);

        $this->assertSame($this->kbb->id, $dataset['meta']['scope_mill_id']);
        $this->assertCount(1, $dataset['mills']);
        $this->assertSame('BBJ', $dataset['mills'][0]['mill']['code']);
        $this->assertFalse($dataset['flags']['showMillComparison']);
    }

    public function test_monthly_oer_ker_and_throughput_use_pooled_ratios(): void
    {
        $this->createOperation($this->kbb, '2026-07-01', [
            'bts_diproses' => 100,
            'produksi_cpo' => 10,
            'produksi_pk' => 2,
            'jam_operasi' => 1,
        ]);
        $this->createOperation($this->kbb, '2026-07-02', [
            'bts_diproses' => 900,
            'produksi_cpo' => 270,
            'produksi_pk' => 72,
            'jam_operasi' => 99,
        ]);

        $metrics = $this->service->generate($this->admin, 2026, 7, $this->kbb->id)['overall']['metrics'];

        $this->assertSame(28.0, $metrics['oer']);
        $this->assertSame(7.4, $metrics['ker']);
        $this->assertSame(10.0, $metrics['throughput']);
        $this->assertSame(100.0, $metrics['jam_proses']);
    }

    public function test_all_official_monthly_kpis_are_evaluated_from_aggregated_actuals(): void
    {
        Carbon::setTestNow('2026-07-31 12:00:00');
        $this->createOperation($this->kbb, '2026-07-01', [
            'bts_diterima' => 500,
            'bts_diproses' => 400,
            'produksi_cpo' => 80,
            'produksi_pk' => 20,
            'pengeluaran_cpo' => 60,
            'pengeluaran_pk' => 10,
            'stok_cpo' => 900,
            'stok_pk' => 400,
            'jam_operasi' => 10,
            'downtime_jam' => 1,
        ]);
        $this->createOperation($this->kbb, '2026-07-31', [
            'bts_diterima' => 700,
            'bts_diproses' => 600,
            'produksi_cpo' => 220,
            'produksi_pk' => 60,
            'pengeluaran_cpo' => 240,
            'pengeluaran_pk' => 70,
            'stok_cpo' => 120,
            'stok_pk' => 60,
            'jam_operasi' => 30,
            'downtime_jam' => 1,
        ]);

        foreach (KpiEvaluationService::indicatorCatalog() as $indicator) {
            if ($indicator['evaluation_basis'] === 'monthly_flow') {
                $this->createFlowSetting($this->kbb, $indicator['code'], [
                    '7' => ['green' => 1000, 'red' => 800],
                ]);
            } else {
                $this->createDirectSetting($this->kbb, $indicator['code'], 100, 50);
            }
        }

        $report = $this->service->generate($this->admin, 2026, 7, $this->kbb->id)['mills'][0];

        $this->assertCount(13, $report['kpi']);
        $this->assertSame(25.0, $report['kpi']['throughput']['actual']);
        $this->assertSame('MT/Jam', $report['kpi']['throughput']['unit']);
        $this->assertSame(300.0, $report['kpi']['pengeluaran_cpo']['actual']);
        $this->assertSame(80.0, $report['kpi']['pengeluaran_pk']['actual']);
        $this->assertSame(300.0, $report['kpi']['jualan_cpo']['actual']);
        $this->assertSame(80.0, $report['kpi']['jualan_pk']['actual']);
        $this->assertSame(120.0, $report['kpi']['stok_cpo']['actual']);
        $this->assertSame(60.0, $report['kpi']['stok_pk']['actual']);
        $this->assertSame(100.0, $report['kpi']['jualan_cpo_vs_pengeluaran_cpo']['actual']);
        $this->assertSame(100.0, $report['kpi']['jualan_pk_vs_pengeluaran_pk']['actual']);
    }

    public function test_inactive_kpi_setting_remains_neutral(): void
    {
        $this->createOperation($this->kbb, '2026-07-31', ['produksi_pk' => 100]);
        $setting = $this->createFlowSetting($this->kbb, 'pengeluaran_pk', [
            '7' => ['green' => 100, 'red' => 80],
        ]);
        $setting->update(['is_active' => false]);

        $result = $this->service->generate($this->admin, 2026, 7, $this->kbb->id)
            ['mills'][0]['kpi']['pengeluaran_pk'];

        $this->assertSame('grey', $result['status']);
        $this->assertSame('Belum Ditetapkan', $result['status_label']);
    }

    public function test_throughput_target_variance_status_and_summary_follow_official_kpi_setting(): void
    {
        $this->createOperation($this->kbb, '2026-07-01', [
            'bts_diproses' => 100,
            'jam_operasi' => 1,
            'throughput' => 100,
        ]);
        $this->createOperation($this->kbb, '2026-07-31', [
            'bts_diproses' => 900,
            'jam_operasi' => 99,
            'throughput' => 9.09,
        ]);

        $datasetWithoutSetting = $this->service->generate($this->admin, 2026, 7, $this->kbb->id);
        $presentationWithoutSetting = app(ManagementMonthlyReportPresentationService::class)
            ->prepare($datasetWithoutSetting);
        $throughputWithoutSetting = $datasetWithoutSetting['mills'][0]['kpi']['throughput'];

        $this->assertSame(10.0, $throughputWithoutSetting['actual']);
        $this->assertSame('grey', $throughputWithoutSetting['status']);
        $this->assertSame(13, $presentationWithoutSetting['status_counts']['grey']);
        $this->assertSame(0, $presentationWithoutSetting['status_counts']['green']);
        $this->assertSame(0, $presentationWithoutSetting['status_counts']['red']);

        $this->createDirectSetting($this->kbb, 'throughput', 9.0, 7.0);

        $datasetWithSetting = $this->service->generate($this->admin, 2026, 7, $this->kbb->id);
        $presentationWithSetting = app(ManagementMonthlyReportPresentationService::class)
            ->prepare($datasetWithSetting);
        $throughput = $datasetWithSetting['mills'][0]['kpi']['throughput'];
        $card = collect($datasetWithSetting['mills'][0]['executiveCards'])->firstWhere('code', 'throughput');

        $this->assertSame(10.0, $throughput['actual']);
        $this->assertSame(9.0, $throughput['green_threshold']);
        $this->assertSame(1.0, $throughput['variance']);
        $this->assertSame('green', $throughput['status']);
        $this->assertSame($throughput, $card['kpi']);
        $this->assertSame(12, $presentationWithSetting['status_counts']['grey']);
        $this->assertSame(1, $presentationWithSetting['status_counts']['green']);

        $scorecard = collect($presentationWithSetting['mill_scorecards'][0]['items'])
            ->firstWhere('code', 'throughput');
        $this->assertSame(10.0, $scorecard['actuals'][0]['value']);
        $this->assertSame(9.0, $scorecard['target']);
        $this->assertSame(1.0, $scorecard['variances'][0]['value']);
        $this->assertSame('green', $scorecard['status']);
    }

    public function test_zero_operating_hours_makes_throughput_not_applicable_without_inf_or_nan(): void
    {
        $this->createOperation($this->kbb, '2026-07-31', [
            'bts_diproses' => 500,
            'jam_operasi' => 0,
        ]);
        $this->createDirectSetting($this->kbb, 'throughput', 25.0, 20.0);

        $dataset = $this->service->generate($this->admin, 2026, 7, $this->kbb->id);
        $throughput = $dataset['mills'][0]['kpi']['throughput'];

        $this->assertNull($dataset['overall']['metrics']['throughput']);
        $this->assertNull($throughput['actual']);
        $this->assertSame('grey', $throughput['status']);
        $this->assertSame('Tiada Data', $throughput['status_label']);
        $this->assertIsString(json_encode($dataset, JSON_THROW_ON_ERROR));
    }

    public function test_completed_month_uses_month_end_for_monthly_flow_evaluation(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');
        $this->createOperation($this->kbb, '2026-07-16', ['produksi_cpo' => 600]);
        $this->createFlowSetting($this->kbb, 'pengeluaran_cpo', [
            '7' => ['green' => 1000, 'red' => 800],
        ]);

        $dataset = $this->service->generate($this->admin, 2026, 7, $this->kbb->id);
        $result = $dataset['mills'][0]['kpi']['pengeluaran_cpo'];

        $this->assertSame('2026-07-31', $dataset['meta']['as_of_date']);
        $this->assertSame(1000.0, $result['green_threshold']);
        $this->assertSame('red', $result['status']);
    }

    public function test_current_month_uses_latest_operation_date_and_prorates_monthly_flow(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');
        $this->createOperation($this->kbb, '2026-07-10', ['produksi_cpo' => 300]);
        $this->createOperation($this->kbb, '2026-07-16', ['produksi_cpo' => 200]);
        $this->createFlowSetting($this->kbb, 'pengeluaran_cpo', [
            '7' => ['green' => 1000, 'red' => 800],
        ]);

        $dataset = $this->service->generate($this->admin, 2026, 7, $this->kbb->id);
        $result = $dataset['mills'][0]['kpi']['pengeluaran_cpo'];

        $this->assertSame('2026-07-16', $dataset['meta']['as_of_date']);
        $this->assertSame(516.13, $result['green_threshold']);
        $this->assertSame(412.9, $result['red_threshold']);
        $this->assertSame('yellow', $result['status']);
    }

    public function test_report_without_data_has_no_as_of_date_or_fabricated_achievement(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');
        $this->createFlowSetting($this->kbb, 'pengeluaran_cpo', [
            '7' => ['green' => 1000, 'red' => 800],
        ]);

        $dataset = $this->service->generate($this->admin, 2026, 7, $this->kbb->id);
        $result = $dataset['mills'][0]['kpi']['pengeluaran_cpo'];

        $this->assertNull($dataset['meta']['as_of_date']);
        $this->assertNull($result['expected_target_to_date']);
        $this->assertSame('Tiada Data', $result['status_label']);
    }

    public function test_monthly_downtime_uses_pooled_percentage_and_zero_hours_is_not_applicable(): void
    {
        $this->createOperation($this->kbb, '2026-07-01', ['jam_operasi' => 1, 'downtime_jam' => 1]);
        $this->createOperation($this->kbb, '2026-07-02', ['jam_operasi' => 99, 'downtime_jam' => 1]);

        $dataset = $this->service->generate($this->admin, 2026, 7, $this->kbb->id);
        $this->assertSame(2.0, $dataset['overall']['metrics']['downtime_percentage']);
        $this->assertSame(2.0, $dataset['mills'][0]['kpi']['downtime']['actual_percentage']);

        DailyOperation::query()->delete();
        $this->createOperation($this->kbb, '2026-07-03', ['jam_operasi' => 0, 'downtime_jam' => 2, 'operation_status' => 'Tidak Operasi']);
        $zeroDataset = $this->service->generate($this->admin, 2026, 7, $this->kbb->id);

        $this->assertNull($zeroDataset['overall']['metrics']['downtime_percentage']);
        $this->assertSame('Tidak Berkenaan', $zeroDataset['mills'][0]['kpi']['downtime']['status_label']);
        $this->assertSame('grey', $zeroDataset['mills'][0]['kpi']['downtime']['status']);
    }

    public function test_product_flow_uses_opening_and_closing_snapshots_not_daily_stock_sum(): void
    {
        $this->createOperation($this->kbb, '2026-07-01', [
            'stok_cpo_yesterday' => 100,
            'stok_cpo' => 110,
            'stok_pk_yesterday' => 50,
            'stok_pk' => 55,
            'produksi_cpo' => 20,
            'produksi_pk' => 10,
            'pengeluaran_cpo' => 10,
            'pengeluaran_pk' => 5,
        ]);
        $this->createOperation($this->kbb, '2026-07-31', [
            'stok_cpo_yesterday' => 110,
            'stok_cpo' => 120,
            'stok_pk_yesterday' => 55,
            'stok_pk' => 60,
            'produksi_cpo' => 30,
            'produksi_pk' => 15,
            'pengeluaran_cpo' => 20,
            'pengeluaran_pk' => 10,
        ]);

        $flow = $this->service->generate($this->admin, 2026, 7, $this->kbb->id)['mills'][0]['productFlow'];

        $this->assertSame(100.0, $flow['cpo']['opening_stock']);
        $this->assertSame(120.0, $flow['cpo']['closing_stock']);
        $this->assertSame(50.0, $flow['pk']['opening_stock']);
        $this->assertSame(60.0, $flow['pk']['closing_stock']);
        $this->assertNotSame(230.0, $flow['cpo']['closing_stock']);
    }

    public function test_missing_kpi_is_neutral_and_report_page_still_generates(): void
    {
        $this->seedMonthlyOperations();

        $dataset = $this->service->generate($this->admin, 2026, 7, $this->kbb->id);

        $this->assertFalse($dataset['flags']['hasKpiTargets']);
        $this->assertSame('grey', $dataset['mills'][0]['kpi']['bts']['status']);
        $this->assertSame('Belum Ditetapkan', $dataset['mills'][0]['kpi']['bts']['status_label']);
        $this->assertSame([], $dataset['highlights']['attentionItems']);

        $this->actingAs($this->admin)
            ->get(route('laporan-pengurusan-bulanan.index', ['bulan' => 7, 'tahun' => 2026, 'mill_id' => $this->kbb->id]))
            ->assertOk()
            ->assertSee('KPI Belum Ditetapkan', false)
            ->assertSee('JULAI 2026', false);
    }

    public function test_combined_bts_uses_one_target_two_actuals_and_service_status(): void
    {
        $this->createOperation($this->kbb, '2026-07-31', ['bts_diterima' => 1100, 'bts_diproses' => 900]);
        $this->createFlowSetting($this->kbb, 'bts_diterima_dan_diproses', ['7' => ['green' => 1000, 'red' => 800]]);

        $result = $this->service->generate($this->admin, 2026, 7, $this->kbb->id)['mills'][0]['kpi']['bts'];
        $expected = app(KpiEvaluationService::class)->evaluateBtsCombined(1100, 900, $this->kbb->id, 2026, 7, true, '2026-07-31');

        $this->assertSame($expected, $result);
        $this->assertSame(1000.0, $result['target']);
        $this->assertSame(1100.0, $result['actual_bts_diterima']);
        $this->assertSame(900.0, $result['actual_bts_diproses']);
        $this->assertSame('yellow', $result['status']);
    }

    public function test_trend_is_date_sorted_and_does_not_create_missing_days(): void
    {
        $this->createOperation($this->kbb, '2026-07-20', ['bts_diterima' => 20]);
        $this->createOperation($this->kbb, '2026-07-02', ['bts_diterima' => 2]);

        $trend = $this->service->generate($this->admin, 2026, 7, $this->kbb->id)['overall']['trend'];

        $this->assertCount(2, $trend);
        $this->assertSame(['2026-07-02', '2026-07-20'], array_column($trend, 'date'));
        $this->assertNotContains('2026-07-03', array_column($trend, 'date'));
    }

    public function test_daily_oer_and_ker_trends_are_null_when_not_operating_and_keep_operating_zero(): void
    {
        $this->createOperation($this->kbb, '2026-07-01', [
            'operation_status' => 'Tidak Operasi (Terima Buah Sahaja)',
            'bts_diterima' => 100,
            'bts_diproses' => 0,
            'produksi_cpo' => 0,
            'produksi_pk' => 0,
        ]);
        $this->createOperation($this->kbb, '2026-07-02', [
            'operation_status' => 'Operasi',
            'bts_diproses' => 100,
            'produksi_cpo' => 0,
            'produksi_pk' => 0,
        ]);

        $trend = $this->service->generate($this->admin, 2026, 7, $this->kbb->id)['overall']['trend'];

        $this->assertSame(['2026-07-01', '2026-07-02'], array_column($trend, 'date'));
        $this->assertSame([null, 0.0], array_column($trend, 'oer'));
        $this->assertSame([null, 0.0], array_column($trend, 'ker'));
    }

    public function test_dynamic_flags_and_operational_issue_observations_follow_available_data(): void
    {
        $this->createOperation($this->kbb, '2026-07-18', [
            'produksi_cpo' => 10,
            'downtime_jam' => 2,
            'isu_operasi' => 'Kerosakan conveyor',
            'tindakan_pembetulan' => 'Pembaikan dilaksanakan',
        ]);

        $dataset = $this->service->generate($this->admin, 2026, 7, $this->kbb->id);

        $this->assertFalse($dataset['flags']['showMillComparison']);
        $this->assertTrue($dataset['flags']['hasOperationalIssues']);
        $this->assertTrue($dataset['flags']['hasProductionData']);
        $this->assertTrue($dataset['flags']['hasDowntimeData']);
        $this->assertSame('Kerosakan conveyor', $dataset['mills'][0]['operationalIssues'][0]['issue']);
        $this->assertContains(
            'recorded_operational_issue',
            array_column($dataset['highlights']['operationalObservations'], 'type')
        );
    }

    public function test_existing_authenticated_roles_keep_access_and_guests_are_redirected(): void
    {
        foreach ([$this->admin, $this->pengurusan, $this->pegawaiKbb, $this->pengurusKahang] as $user) {
            $this->actingAs($user)
                ->get(route('laporan-pengurusan-bulanan.index', ['bulan' => 7, 'tahun' => 2026]))
                ->assertOk();
        }

        Auth::logout();
        $this->get(route('laporan-pengurusan-bulanan.index'))
            ->assertRedirect(route('login'));
    }

    private function seedMonthlyOperations(): void
    {
        $this->createOperation($this->kbb, '2026-07-01', ['bts_diterima' => 100, 'bts_diproses' => 80, 'jam_operasi' => 8]);
        $this->createOperation($this->kbb, '2026-07-20', ['bts_diterima' => 1000, 'bts_diproses' => 900, 'jam_operasi' => 40]);
        $this->createOperation($this->kahang, '2026-07-10', ['bts_diterima' => 1000, 'bts_diproses' => 950, 'jam_operasi' => 45]);
    }

    private function createMill(string $name, string $code): Mill
    {
        return Mill::create(['name' => $name, 'code' => $code, 'location' => 'Johor', 'is_active' => true]);
    }

    private function createUser(string $email, Role $role, ?Mill $mill = null): User
    {
        return User::create([
            'name' => $role->label,
            'email' => $email,
            'password' => bcrypt('secret'),
            'role_id' => $role->id,
            'mill_id' => $mill?->id,
            'is_active' => true,
        ]);
    }

    private function createOperation(Mill $mill, string $date, array $overrides = []): DailyOperation
    {
        return DailyOperation::create(array_merge([
            'tarikh' => $date,
            'mill_id' => $mill->id,
            'shift' => 'Harian',
            'officer_id' => $this->pegawaiKbb->id,
            'operation_status' => 'Operasi',
            'bts_diterima' => 0,
            'bts_diproses' => 0,
            'baki_bts_semalam' => 0,
            'baki_bts_selepas_diproses' => 0,
            'jam_operasi' => 0,
            'downtime_jam' => 0,
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
        ], $overrides));
    }

    private function createFlowSetting(Mill $mill, string $code, array $monthlyTargets): KpiIndicatorSetting
    {
        $indicator = KpiEvaluationService::indicatorMap()[$code];

        return KpiIndicatorSetting::create([
            'mill_id' => $mill->id,
            'year' => 2026,
            'indicator_code' => $code,
            'indicator_name' => $indicator['name'],
            'unit' => $indicator['unit'],
            'evaluation_direction' => $indicator['direction'],
            'monthly_targets' => $monthlyTargets,
            'is_active' => true,
        ]);
    }

    private function createDirectSetting(
        Mill $mill,
        string $code,
        float $greenThreshold,
        float $redThreshold
    ): KpiIndicatorSetting {
        $indicator = KpiEvaluationService::indicatorMap()[$code];

        return KpiIndicatorSetting::create([
            'mill_id' => $mill->id,
            'year' => 2026,
            'indicator_code' => $code,
            'indicator_name' => $indicator['name'],
            'unit' => $indicator['unit'],
            'evaluation_direction' => $indicator['direction'],
            'green_threshold' => $greenThreshold,
            'red_threshold' => $redThreshold,
            'is_active' => true,
        ]);
    }
}
