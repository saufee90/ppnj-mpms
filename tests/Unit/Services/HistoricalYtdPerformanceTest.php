<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MillComparisonController;
use App\Http\Controllers\PerformanceAnalysisController;
use App\Models\DailyOperation;
use App\Models\KpiIndicatorSetting;
use App\Models\Mill;
use App\Models\MillMonthlyHistory;
use App\Models\Role;
use App\Models\User;
use App\Services\DashboardPdfService;
use App\Services\DashboardKpiProgressService;
use App\Services\HistoricalPerformanceImportService;
use App\Services\KpiEvaluationService;
use App\Services\ManagementMonthlyReportService;
use App\Services\ManagementMonthlyPdfService;
use App\Services\ManagementMonthlyReportPresentationService;
use App\Services\MpobPriceService;
use App\Services\YtdPerformanceService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Database\QueryException;
use RuntimeException;
use Tests\TestCase;

class HistoricalYtdPerformanceTest extends TestCase
{
    private Mill $kbb;
    private Mill $kahang;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Carbon::setTestNow('2026-11-04 12:00:00');

        (require database_path('migrations/2024_01_01_000002_create_mills_table.php'))->up();
        (require database_path('migrations/2026_11_04_000001_create_mill_monthly_histories_table.php'))->up();
        (require database_path('migrations/2026_07_17_000001_create_kpi_indicator_settings_table.php'))->up();
        Schema::create('daily_operations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mill_id');
            $table->date('tarikh');
            $table->string('operation_status')->default('Operasi');
            foreach ([
                'bts_diterima', 'bts_diproses', 'jam_operasi', 'produksi_cpo', 'produksi_pk',
                'pengeluaran_cpo', 'pengeluaran_pk', 'downtime_jam', 'baki_bts_semalam',
                'baki_bts_selepas_diproses', 'stok_cpo', 'stok_pk', 'stok_cpo_yesterday', 'stok_pk_yesterday',
                'ffa', 'moisture', 'dirt', 'utilisation_rate',
            ] as $field) {
                $table->decimal($field, 16, 2)->default(0);
            }
            $table->text('isu_operasi')->nullable();
            $table->text('tindakan_pembetulan')->nullable();
            $table->timestamps();
        });
        Schema::create('mpob_price_histories', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->date('trade_date');
            $table->decimal('price', 16, 2);
        });
        $this->kbb = Mill::create(['name' => 'KBB', 'code' => 'BBJ', 'location' => 'Test', 'is_active' => true]);
        $this->kahang = Mill::create(['name' => 'Kahang', 'code' => 'KHG', 'location' => 'Test', 'is_active' => true]);
        $this->user = new User(['name' => 'YTD test', 'is_active' => true]);
        $this->user->setRelation('role', new Role(['name' => Role::ADMIN]));
        $this->user->setRelation('mill', null);
        $this->actingAs($this->user);
        view()->share('errors', new ViewErrorBag());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_exact_source_months_totals_and_idempotent_non_overwriting_import(): void
    {
        $importer = app(HistoricalPerformanceImportService::class);
        $source = $importer->dataset();
        $this->assertCount(12, $source);
        $this->assertSame(['inserted' => 12, 'unchanged' => 0], $importer->import(true));
        $this->assertSame(0, MillMonthlyHistory::count());
        $this->assertSame(['inserted' => 12, 'unchanged' => 0], $importer->import());
        $before = MillMonthlyHistory::all()->toArray();
        $this->assertSame(['inserted' => 0, 'unchanged' => 12], $importer->import());
        $this->assertSame($before, MillMonthlyHistory::all()->toArray());
        foreach ([$this->kahang, $this->kbb] as $mill) {
            $rows = MillMonthlyHistory::where('mill_id', $mill->id)->orderBy('month')->get();
            $this->assertSame(range(1, 6), $rows->pluck('month')->all());
            foreach (MillMonthlyHistory::FIELDS as $index => $field) {
                $this->assertEqualsWithDelta(
                    (float) HistoricalPerformanceImportService::EXPECTED_TOTALS[$mill->code][$index],
                    (float) $rows->sum($field),
                    .00001
                );
            }
            foreach ($source as $row) {
                if ($row['mill_code'] !== $mill->code) {
                    continue;
                }
                foreach (MillMonthlyHistory::FIELDS as $field) {
                    $this->assertSame($row[$field], $rows->firstWhere('month', (int) $row['month'])->{$field});
                }
            }
        }
        $this->assertSame(0, DailyOperation::count());
        $this->assertFalse(Schema::hasColumn('mill_monthly_histories', 'stok_cpo'));
        $this->assertFalse(Schema::hasColumn('mill_monthly_histories', 'oer'));
    }

    public function test_conflicting_history_aborts_the_whole_import(): void
    {
        MillMonthlyHistory::create([
            'mill_id' => $this->kbb->id, 'year' => 2026, 'month' => 6,
            ...array_fill_keys(MillMonthlyHistory::FIELDS, '0.00'),
        ]);
        try {
            app(HistoricalPerformanceImportService::class)->import();
            $this->fail('A conflicting historical record must not be overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('differs', $exception->getMessage());
        }
        $this->assertSame(1, MillMonthlyHistory::count());
    }

    public function test_unique_mill_year_month_constraint_is_enforced(): void
    {
        app(HistoricalPerformanceImportService::class)->import();
        $duplicate = MillMonthlyHistory::first()->replicate();
        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_import_command_refuses_non_local_snapshot_database(): void
    {
        $this->artisan('mps:import-history-2026')
            ->expectsOutputToContain('Stopped:')
            ->assertExitCode(1);
        $this->assertSame(0, MillMonthlyHistory::count());
    }

    public function test_ytd_matches_snapshot_and_combines_totals_not_percentages(): void
    {
        $this->loadSnapshot();
        $service = app(YtdPerformanceService::class);
        $asOf = Carbon::parse('2026-11-03');
        $expected = [
            'BBJ' => [88677.02, 88495.02, 17437.91, 16855.55, 3889.24, 3339.32, 19.70, 4.39, 3046.00],
            'KHG' => [130530.05, 129923.05, 24952.61, 24237.76, 6515.47, 6420.50, 19.21, 5.01, 2990.15],
        ];
        $fields = ['bts_diterima', 'bts_diproses', 'pengeluaran_cpo', 'jualan_cpo', 'pengeluaran_pk', 'jualan_pk', 'oer', 'ker', 'jam_operasi'];
        foreach ([$this->kbb, $this->kahang] as $mill) {
            $metrics = $service->metrics($service->rows($asOf, [$mill->id]));
            foreach ($fields as $index => $field) {
                $this->assertEqualsWithDelta($expected[$mill->code][$index], $metrics[$field], .00001);
            }
        }
        $all = $service->metrics($service->rows($asOf, [$this->kbb->id, $this->kahang->id]));
        $this->assertSame(219207.07, $all['bts_diterima']);
        $this->assertSame(218418.07, $all['bts_diproses']);
        $this->assertSame(round(42390.52 / 218418.07 * 100, 2), $all['oer']);
        $this->assertSame(round(10404.71 / 218418.07 * 100, 2), $all['ker']);
        $this->assertSame(round(218418.07 / 6036.15, 2), $all['throughput']);
        $this->assertSame(round(218418.07 / (3046 * 30 + 2990.15 * 60) * 100, 2), $all['utilisation']);
    }

    public function test_completed_month_boundary_partial_history_and_calendar_years(): void
    {
        app(HistoricalPerformanceImportService::class)->import();
        $this->operation($this->kbb, '2026-06-22', ['bts_diproses' => 99999]);
        $this->operation($this->kbb, '2026-06-30', ['bts_diproses' => 99999]);
        $this->operation($this->kbb, '2026-07-01', ['bts_diproses' => 100, 'jam_operasi' => 5]);
        $this->operation($this->kbb, '2026-12-31', ['bts_diproses' => 200]);
        $this->operation($this->kbb, '2027-01-01', ['bts_diproses' => 300, 'jam_operasi' => 10]);
        $this->operation($this->kbb, '2027-01-02', ['bts_diproses' => 400]);
        $service = app(YtdPerformanceService::class);
        $metrics = fn (string $date) => $service->metrics($service->rows(Carbon::parse($date), [$this->kbb->id]));
        $this->assertNull($metrics('2026-01-15')['bts_diproses']);
        $this->assertSame(8380.97, $metrics('2026-01-31')['bts_diproses']);
        $this->assertSame(8380.97, $metrics('2026-02-15')['bts_diproses']);
        $this->assertSame(1662.11, $metrics('2026-01-31')['pengeluaran_cpo']);
        $this->assertSame(34936.43, $metrics('2026-06-29')['bts_diproses']);
        $this->assertSame(42320.55, $metrics('2026-06-30')['bts_diproses']);
        $this->assertSame(42420.55, $metrics('2026-07-01')['bts_diproses']);
        $this->assertSame(42620.55, $metrics('2026-12-31')['bts_diproses']);
        $this->assertSame(300.0, $metrics('2027-01-01')['bts_diproses']);
        $this->assertNull($metrics('2025-12-31')['oer']);
        $this->assertStringContainsString('bulan pilihan tidak disertakan', $service->note(Carbon::parse('2026-06-15')));
        $this->assertNull($service->note(Carbon::parse('2027-01-01')));
        $this->assertSame(6, DailyOperation::count());
    }

    public function test_missing_history_is_an_explicit_error_and_zero_denominators_are_safe(): void
    {
        $service = app(YtdPerformanceService::class);
        $zero = $service->metrics(collect([(object) ['mill_id' => $this->kbb->id, 'bts_diproses' => 0, 'jam_operasi' => 0]]));
        $this->assertNull($zero['throughput']);
        $this->assertNull($zero['oer']);
        $this->assertNull($zero['ker']);
        $this->assertNull($zero['utilisation']);
        $this->expectException(RuntimeException::class);
        $service->rows(Carbon::parse('2026-11-03'), [$this->kbb->id]);
    }

    public function test_import_preserves_daily_stock_and_non_operating_sales_remain_in_ytd(): void
    {
        $this->operation($this->kbb, '2026-07-01', [
            'operation_status' => 'Tidak Operasi (Terima Buah Sahaja)',
            'bts_diterima' => 100, 'pengeluaran_cpo' => 7, 'pengeluaran_pk' => 8,
        ]);
        $before = DB::table('daily_operations')->get()->toJson();
        app(HistoricalPerformanceImportService::class)->import();
        $service = app(YtdPerformanceService::class);
        $ytd = $service->metrics($service->rows(Carbon::parse('2026-07-01'), [$this->kbb->id]));
        $this->assertSame(42420.55, $ytd['bts_diterima']);
        $this->assertSame(42320.55, $ytd['bts_diproses']);
        $this->assertSame(8220.02, $ytd['jualan_cpo']);
        $this->assertSame(1898.06, $ytd['jualan_pk']);
        $this->assertSame($before, DB::table('daily_operations')->get()->toJson());
    }

    public function test_dashboard_periods_stock_cutoff_authorization_and_kpi_evaluation(): void
    {
        $this->loadSnapshot();
        $this->assertTrue($this->user->canSelectDashboardMill());
        $before = DB::table('daily_operations')->orderBy('id')->get()->toJson();
        KpiIndicatorSetting::create([
            'mill_id' => $this->kbb->id, 'year' => 2026, 'indicator_code' => 'oer',
            'indicator_name' => 'OER', 'unit' => '%', 'evaluation_direction' => 'higher_is_better',
            'green_threshold' => 19.75, 'red_threshold' => 19, 'is_active' => true,
        ]);
        $data = $this->dashboard('ytd', $this->kbb->id);
        $this->assertSame(88495.02, $data['periodMetrics']['bts_diproses']);
        $this->assertSame(19.70, $data['comparisonMetrics']['total']['oer']);
        $this->assertSame('yellow', $data['kpiStatuses']['oer']['tone']);
        $this->assertSame(777.0, (float) $data['operationalStatusByMill']->first()['stok_cpo']);
        $this->assertSame(46174.47, $this->dashboard('mtd', $this->kbb->id)['periodMetrics']['bts_diproses']);
        $this->assertSame(46174.47, $this->dashboard('harian', $this->kbb->id)['periodMetrics']['bts_diproses']);
        $this->assertSame(218418.07, $this->dashboard('ytd', null)['periodMetrics']['bts_diproses']);
        $this->user->mill_id = $this->kbb->id;
        $this->user->setRelation('role', new Role(['name' => Role::PEGAWAI_KILANG]));
        $this->assertFalse($this->user->canSelectDashboardMill());
        $scoped = $this->dashboard('ytd', $this->kahang->id);
        $this->assertSame($this->kbb->id, $scoped['selectedMillId']);
        $this->assertSame(88495.02, $scoped['periodMetrics']['bts_diproses']);
        $this->assertSame($before, DB::table('daily_operations')->orderBy('id')->get()->toJson());
        $this->assertSame('2026-01-01', app(KpiEvaluationService::class)->getPeriodBounds(2026)['ytd_start_date']);
        $this->assertSame(range(7, 12), app(KpiEvaluationService::class)->getApplicableMonths(2026));
    }

    public function test_marketing_executive_can_select_both_mills_for_each_dashboard_period(): void
    {
        $this->loadSnapshot();
        $this->user->setRelation('role', new Role(['name' => Role::EKSEKUTIF_PEMASARAN_BTS]));

        $this->assertTrue($this->user->canSelectDashboardMill());
        $this->assertFalse($this->user->canViewAllMills());
        $this->assertFalse($this->user->canEditData());
        $this->assertFalse($this->user->canDeleteData());

        $expected = [
            'harian' => [$this->kbb->id => 46174.47, $this->kahang->id => 65272.23, 'combined' => 111446.70],
            'mtd' => [$this->kbb->id => 46174.47, $this->kahang->id => 65272.23, 'combined' => 111446.70],
            'ytd' => [$this->kbb->id => 88495.02, $this->kahang->id => 129923.05, 'combined' => 218418.07],
        ];

        foreach (['harian', 'mtd', 'ytd'] as $period) {
            $combined = $this->dashboard($period, null, '2026-11-03', $period === 'harian');
            $this->assertNull($combined['selectedMillId']);
            $this->assertEqualsWithDelta(
                $expected[$period]['combined'],
                $combined['periodMetrics']['bts_diproses'],
                0.001
            );
            $this->assertCount($period === 'harian' ? 0 : 9, $combined['kpiProgress']);
            $this->assertSame('2026-11-03', $combined['displayDateCarbon']->toDateString());

            foreach ([$this->kbb, $this->kahang] as $mill) {
                $selected = $this->dashboard($period, $mill->id);
                $this->assertSame($mill->id, $selected['selectedMillId']);
                $this->assertEqualsWithDelta(
                    $expected[$period][$mill->id],
                    $selected['periodMetrics']['bts_diproses'],
                    0.001
                );
                $this->assertSame($mill->id, $selected['operationalStatusByMill']->first()['mill_id']);
            }
        }

        $historicalDate = $this->dashboard('harian', $this->kbb->id, '2026-11-02');
        $this->assertSame('2026-11-02', $historicalDate['displayDateCarbon']->toDateString());
        $this->assertNull($historicalDate['periodMetrics']['bts_diproses']);
    }

    public function test_mill_comparison_uses_historical_months_and_daily_data_without_overlap(): void
    {
        $this->loadSnapshot();

        foreach ([1, 6] as $month) {
            $comparison = $this->millComparison(2026, $month);
            $comparisonHtml = $this->millComparisonView(2026, $month)->render();
            $this->assertStringContainsString('id="chartProd"', $comparisonHtml);
            $this->assertStringContainsString('id="chartKpi"', $comparisonHtml);
            $this->assertStringContainsString(json_encode($comparison->pluck('bts_diproses')->all()), $comparisonHtml);
            $this->assertStringContainsString(json_encode($comparison->pluck('oer')->all()), $comparisonHtml);
            foreach ([$this->kbb, $this->kahang] as $mill) {
                $history = MillMonthlyHistory::query()
                    ->where('mill_id', $mill->id)
                    ->where('year', 2026)
                    ->where('month', $month)
                    ->firstOrFail();
                $row = $comparison->firstWhere('mill', $mill->name);
                $processed = (float) $history->bts_diproses;
                $throughput = $processed / (float) $history->jam_operasi;

                $this->assertSame(round($processed, 2), $row['bts_diproses']);
                $this->assertSame(round((float) $history->jualan_cpo, 2), $row['cpo']);
                $this->assertSame(round((float) $history->jualan_pk, 2), $row['pk']);
                $this->assertSame(round((float) $history->pengeluaran_cpo / $processed * 100, 2), $row['oer']);
                $this->assertSame(round((float) $history->pengeluaran_pk / $processed * 100, 2), $row['ker']);
                $this->assertSame(round($throughput, 2), $row['throughput']);
                $this->assertSame(
                    round($throughput / ($mill->code === 'BBJ' ? 30 : 60) * 100, 2),
                    $row['utilisation']
                );
                $this->assertNull($row['downtime']);
                $this->assertNull($row['ffa']);
            }
        }

        $juneRow = $this->millComparison(2026, 6)->firstWhere('mill', $this->kbb->name);
        $juneHistory = MillMonthlyHistory::query()
            ->where('mill_id', $this->kbb->id)->where('year', 2026)->where('month', 6)->firstOrFail();
        $this->assertSame(round((float) $juneHistory->bts_diproses, 2), $juneRow['bts_diproses']);
        $this->assertNotSame(99999.0, $juneRow['bts_diproses']);
        $juneHtml = $this->millComparisonView(2026, 6)->render();
        $this->assertStringContainsString('—', $juneHtml);

        $this->operation($this->kbb, '2026-07-12', [
            'bts_diproses' => 600, 'jam_operasi' => 20, 'produksi_cpo' => 100,
            'pengeluaran_cpo' => 80, 'produksi_pk' => 50, 'pengeluaran_pk' => 20,
            'downtime_jam' => 3, 'ffa' => 2.5,
        ]);
        $this->operation($this->kahang, '2026-07-15', [
            'bts_diproses' => 1200, 'jam_operasi' => 30, 'produksi_cpo' => 300,
            'pengeluaran_cpo' => 200, 'produksi_pk' => 100, 'pengeluaran_pk' => 40,
            'downtime_jam' => 4, 'ffa' => 3.5,
        ]);
        $july = $this->millComparison(2026, 7);
        $kbbJuly = $july->firstWhere('mill', $this->kbb->name);
        $khgJuly = $july->firstWhere('mill', $this->kahang->name);
        $this->assertSame(600.0, $kbbJuly['bts_diproses']);
        $this->assertSame(16.67, $kbbJuly['oer']);
        $this->assertSame(30.0, $kbbJuly['throughput']);
        $this->assertSame(100.0, $kbbJuly['utilisation']);
        $this->assertSame(1200.0, $khgJuly['bts_diproses']);
        $this->assertSame(25.0, $khgJuly['oer']);
        $this->assertSame(40.0, $khgJuly['throughput']);
        $this->assertSame(66.67, $khgJuly['utilisation']);
        $this->assertSame(4.0, $khgJuly['downtime']);
        $this->assertSame(3.5, $khgJuly['ffa']);

        $annual = $this->millComparison(2026, null);
        foreach ([$this->kbb, $this->kahang] as $mill) {
            $expected = app(YtdPerformanceService::class)->metrics(
                app(YtdPerformanceService::class)->rows(Carbon::parse('2026-11-03'), [$mill->id])
            );
            $row = $annual->firstWhere('mill', $mill->name);
            $this->assertSame($expected['bts_diproses'], $row['bts_diproses']);
            $this->assertSame($expected['oer'], $row['oer']);
            $this->assertSame($expected['ker'], $row['ker']);
            $this->assertSame($expected['throughput'], $row['throughput']);
            $this->assertSame($expected['utilisation'], $row['utilisation']);
            $this->assertNull($row['downtime']);
            $this->assertNull($row['ffa']);
        }

        $this->assertContains('auth', \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('perbandingan.index')->gatherMiddleware());
    }

    public function test_performance_analysis_uses_historical_and_daily_monthly_rows_without_double_counting(): void
    {
        $this->loadSnapshot();
        $this->operation($this->kbb, '2026-07-12', [
            'bts_diterima' => 650, 'bts_diproses' => 600, 'jam_operasi' => 20,
            'produksi_cpo' => 150, 'pengeluaran_cpo' => 120,
            'produksi_pk' => 60, 'pengeluaran_pk' => 30, 'downtime_jam' => 2,
        ]);
        $this->operation($this->kahang, '2026-07-15', [
            'bts_diterima' => 1300, 'bts_diproses' => 1200, 'jam_operasi' => 30,
            'produksi_cpo' => 300, 'pengeluaran_cpo' => 250,
            'produksi_pk' => 120, 'pengeluaran_pk' => 60, 'downtime_jam' => 4,
        ]);
        $this->operation($this->kbb, '2025-01-15', [
            'bts_diproses' => 300, 'jam_operasi' => 10, 'produksi_cpo' => 75,
            'pengeluaran_cpo' => 60, 'produksi_pk' => 30, 'downtime_jam' => 1,
        ]);

        foreach ([1, 6] as $month) {
            $comparison = $this->analysis(2026);
            $historical = MillMonthlyHistory::query()
                ->where('year', 2026)->where('month', $month)->get()->keyBy('mill_id');
            foreach ([$this->kbb, $this->kahang] as $mill) {
                $row = $comparison['monthlyStats'][$month - 1];
                $history = $historical->get($mill->id);
                $millRow = $this->analysis(2026, $mill->id)['monthlyStats'][$month - 1];
                $processed = (float) $history->bts_diproses;
                $this->assertSame(round($processed, 2), $millRow['bts_diproses']);
                $this->assertSame(
                    round((float) $history->pengeluaran_cpo / $processed * 100, 2),
                    $millRow['oer']
                );
                $this->assertSame(
                    round((float) $history->pengeluaran_pk / $processed * 100, 2),
                    $millRow['ker']
                );
                $throughput = $processed / (float) $history->jam_operasi;
                $capacity = $mill->code === 'BBJ' ? 30 : 60;
                $this->assertSame(round($throughput, 2), $millRow['throughput']);
                $this->assertSame(round($throughput / $capacity * 100, 2), $millRow['utilisation']);
                $this->assertNull($millRow['downtime']);
                $this->assertNull($row['downtime']);
            }

            $combinedRow = $comparison['monthlyStats'][$month - 1];
            $totalProcessed = (float) $historical->sum('bts_diproses');
            $this->assertSame(round($totalProcessed, 2), $combinedRow['bts_diproses']);
            $this->assertSame(
                round((float) $historical->sum('pengeluaran_cpo') / $totalProcessed * 100, 2),
                $combinedRow['oer']
            );
            $this->assertSame(
                round((float) $historical->sum('pengeluaran_pk') / $totalProcessed * 100, 2),
                $combinedRow['ker']
            );
        }

        $june = $this->analysis(2026)['monthlyStats'][5];
        $historicalJuneTotal = (float) MillMonthlyHistory::query()
            ->where('year', 2026)->where('month', 6)->sum('bts_diproses');
        $this->assertSame(round($historicalJuneTotal, 2), $june['bts_diproses']);
        $this->assertNotSame(round($historicalJuneTotal + 99999, 2), $june['bts_diproses']);

        $july = $this->analysis(2026)['monthlyStats'][6];
        $this->assertSame(1800.0, $july['bts_diproses']);
        $this->assertSame(25.0, $july['oer']);
        $this->assertSame(36.0, $july['throughput']);
        $this->assertSame(75.0, $july['utilisation']);
        $this->assertSame(6.0, $july['downtime']);

        $annual = $this->analysis(2026)['monthlyStats'];
        $yearRows = app(YtdPerformanceService::class)->rows(Carbon::parse('2026-11-03'), [
            $this->kbb->id,
            $this->kahang->id,
        ]);
        $this->assertEqualsWithDelta(
            (float) $yearRows->sum('bts_diproses'),
            (float) collect($annual)->sum('bts_diproses'),
            0.001
        );
        $this->assertNull($annual[11]['bts_diproses']);
        $this->assertNull($annual[11]['cpo']);
        $this->assertNull($annual[11]['downtime']);

        $january = $this->analysis(2026, $this->kbb->id)['monthlyStats'][0];
        $januaryHtml = $this->analysisView(2026, $this->kbb->id)->render();
        $this->assertStringContainsString(
            'data: [' . number_format($january['bts_diproses'], 2, '.', '') . ',',
            $januaryHtml
        );
        $this->assertStringContainsString('—', $januaryHtml);

        $legacy = $this->analysis(2025, $this->kbb->id)['monthlyStats'][0];
        $this->assertSame(300.0, $legacy['bts_diproses']);
        $this->assertSame(25.0, $legacy['oer']);
        $this->assertSame(1.0, $legacy['downtime']);
        $this->assertContains('auth', \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('analisis.index')->gatherMiddleware());

        $this->user->mill_id = $this->kbb->id;
        $this->user->setRelation('role', new Role(['name' => Role::PEGAWAI_KILANG]));
        $millScoped = $this->analysis(2026, $this->kahang->id);
        $this->assertSame(
            (float) MillMonthlyHistory::query()->where('mill_id', $this->kbb->id)->where('year', 2026)->where('month', 1)->value('bts_diproses'),
            $millScoped['monthlyStats'][0]['bts_diproses']
        );
        $this->assertNull($millScoped['monthlyStats'][0]['downtime']);
    }

    public function test_pdf_monthly_report_and_annual_comparison_share_ytd_without_changing_daily_or_stock(): void
    {
        $this->loadSnapshot();
        $before = DB::table('daily_operations')->orderBy('id')->get()->toJson();
        $pdf = app(DashboardPdfService::class)->generate(Carbon::parse('2026-11-03'));
        $this->assertStringStartsWith('%PDF', $pdf['content']);
        $this->assertSame(218418.07, $pdf['ytd']['bts_diproses']);
        $this->assertSame(88495.02, $pdf['mill_summaries']->firstWhere('code', 'BBJ')['ytd']['bts_diproses']);
        $this->assertSame(46174.47, $pdf['mill_summaries']->firstWhere('code', 'BBJ')['bts_diproses']);
        $this->assertSame(777.0, $pdf['mill_summaries']->firstWhere('code', 'BBJ')['stok_cpo']);
        $report = app(ManagementMonthlyReportService::class)->generate($this->user, 2026, 11);
        $this->assertSame('2026-11-03', $report['meta']['ytd_as_of_date']);
        $this->assertSame(218418.07, $report['overall']['ytd']['bts_diproses']);
        $this->assertEquals(
            DailyOperation::forMonth(2026, 11)->sum('bts_diproses'),
            $report['overall']['metrics']['bts_diproses']
        );
        $monthlyPdf = app(ManagementMonthlyPdfService::class)->generate(
            $report,
            app(ManagementMonthlyReportPresentationService::class)->prepare($report)
        );
        $this->assertStringStartsWith('%PDF', $monthlyPdf['content']);
        $single = app(ManagementMonthlyReportService::class)->generate($this->user, 2026, 11, $this->kahang->id);
        $this->assertSame(129923.05, $single['overall']['ytd']['bts_diproses']);
        $comparison = app(MillComparisonController::class)->index(
            Request::create('/perbandingan-kilang', 'GET', ['tahun' => 2026]),
            app(YtdPerformanceService::class)
        )->getData()['comparison'];
        $this->assertSame(88495.02, $comparison->firstWhere('mill', 'KBB')['bts_diproses']);
        $this->assertSame($before, DB::table('daily_operations')->orderBy('id')->get()->toJson());
    }

    public function test_ytd_quantity_cards_estimate_missing_january_to_june_targets(): void
    {
        $this->loadSnapshot();
        $before = MillMonthlyHistory::all()->toJson();
        foreach ([
            'bts_diterima_dan_diproses', 'pengeluaran_cpo', 'jualan_cpo', 'pengeluaran_pk', 'jualan_pk',
        ] as $code) {
            $this->flowSetting($this->kbb, $code, 2026, array_fill_keys(range(7, 11), ['green' => 100, 'red' => 80]));
        }
        $data = $this->dashboard('ytd', $this->kbb->id);
        foreach (['bts_diterima', 'bts_diproses', 'pengeluaran_cpo', 'jualan_cpo', 'pengeluaran_pk', 'jualan_pk'] as $key) {
            $this->assertSame(1010.0, $data['kpiProgress'][$key]['target']);
            $this->assertSame('Sasaran YTD (Anggaran)', $data['kpiProgress'][$key]['target_label']);
        }
        $this->assertSame(88495.02, $data['periodMetrics']['bts_diproses']);
        $this->assertSame($before, MillMonthlyHistory::all()->toJson());
    }

    public function test_mtd_quantities_keep_existing_proration_and_daily_quantities_have_no_new_formula(): void
    {
        app(HistoricalPerformanceImportService::class)->import();
        $months = [11 => ['green' => 300, 'red' => 240]];
        foreach (['bts_diterima_dan_diproses', 'pengeluaran_cpo', 'jualan_cpo', 'pengeluaran_pk', 'jualan_pk'] as $code) {
            $this->flowSetting($this->kbb, $code, 2026, $months);
        }
        $this->operation($this->kbb, '2026-11-03', [
            'bts_diterima' => 25, 'bts_diproses' => 24, 'jam_operasi' => 1,
            'produksi_cpo' => 30, 'pengeluaran_cpo' => 25,
            'produksi_pk' => 24, 'pengeluaran_pk' => 30,
        ]);
        $data = $this->dashboard('mtd', $this->kbb->id);
        $expected = [
            'bts_diterima' => ['yellow', 'Perhatian'],
            'bts_diproses' => ['red', 'Belum Capai Sasaran'],
            'pengeluaran_cpo' => ['green', 'Capai Sasaran'],
            'jualan_cpo' => ['yellow', 'Perhatian'],
            'pengeluaran_pk' => ['red', 'Belum Capai Sasaran'],
            'jualan_pk' => ['green', 'Capai Sasaran'],
        ];
        foreach ($expected as $key => [$tone, $label]) {
            $this->assertSame(['tone' => $tone, 'label' => 'BBJ: '.$label], $data['kpiStatuses'][$key]);
        }
        $daily = $this->dashboard('harian', $this->kbb->id);
        foreach (array_keys($expected) as $key) {
            $this->assertSame(['tone' => 'neutral', 'label' => 'BBJ: Tiada Sasaran'], $daily['kpiStatuses'][$key]);
        }
    }

    public function test_dashboard_kpi_progress_uses_calendar_proration_and_90_100_percent_boundaries(): void
    {
        Carbon::setTestNow('2027-11-04 12:00:00');
        $this->operation($this->kbb, '2027-11-03', [
            'bts_diterima' => 900,
            'bts_diproses' => 810,
            'produksi_cpo' => 809.91,
            'pengeluaran_cpo' => 0,
            'produksi_pk' => 0,
            'pengeluaran_pk' => 0,
            'jam_operasi' => 1,
        ]);
        foreach ([
            'bts_diterima_dan_diproses',
            'pengeluaran_cpo',
            'jualan_cpo',
            'pengeluaran_pk',
            'jualan_pk',
        ] as $code) {
            $this->flowSetting($this->kbb, $code, 2027, [
                11 => ['green' => 9000, 'red' => 7200],
            ]);
        }

        $mtd = $this->dashboard('mtd', $this->kbb->id, '2027-11-03');
        $this->assertSame(900.0, $mtd['kpiProgress']['bts_diterima']['target']);
        $this->assertSame(100.0, $mtd['kpiProgress']['bts_diterima']['achievement_percentage']);
        $this->assertSame('green', $mtd['kpiProgress']['bts_diterima']['tone']);
        $this->assertSame(90.0, $mtd['kpiProgress']['bts_diproses']['achievement_percentage']);
        $this->assertSame('yellow', $mtd['kpiProgress']['bts_diproses']['tone']);
        $this->assertEqualsWithDelta(89.99, $mtd['kpiProgress']['pengeluaran_cpo']['achievement_percentage'], .001);
        $this->assertSame('red', $mtd['kpiProgress']['pengeluaran_cpo']['tone']);
        $this->assertEqualsWithDelta(100, $mtd['kpiProgress']['bts_diterima']['bar_percentage'], .001);

        $daily = $this->dashboard('harian', $this->kbb->id, '2027-11-03');
        $this->assertSame([], $daily['kpiProgress']);
        $html = view('dashboard.index', $daily)->render();
        $start = strpos($html, 'id="kpi-heading"');
        $end = strpos($html, 'aria-label="Trend bulanan dan prestasi kilang"', $start);
        $cardSection = substr($html, $start, $end - $start);
        foreach (['Tiada Sasaran', 'Perhatian', 'Belum Capai', 'Sasaran MTD', 'progressbar'] as $hiddenText) {
            $this->assertStringNotContainsString($hiddenText, $cardSection);
        }
    }

    public function test_ytd_dashboard_targets_estimate_2026_first_half_per_mill_and_sum_combined_targets(): void
    {
        $this->loadSnapshot();
        $this->flowSetting($this->kbb, 'bts_diterima_dan_diproses', 2026, [
            7 => ['green' => 1000, 'red' => 800],
            8 => ['green' => 2000, 'red' => 1600],
            9 => ['green' => 3000, 'red' => 2400],
            10 => ['green' => 4000, 'red' => 3200],
            11 => ['green' => null, 'red' => null],
            12 => ['green' => 6000, 'red' => 4800],
        ]);
        $this->flowSetting($this->kahang, 'bts_diterima_dan_diproses', 2026, [
            7 => ['green' => 2000, 'red' => 1600],
            8 => ['green' => 3000, 'red' => 2400],
            9 => ['green' => 4000, 'red' => 3200],
            10 => ['green' => 5000, 'red' => 4000],
            11 => ['green' => 6000, 'red' => 4800],
            12 => ['green' => 7000, 'red' => 5600],
        ]);

        $kbb = $this->dashboard('ytd', $this->kbb->id);
        $kahang = $this->dashboard('ytd', $this->kahang->id);
        $combined = $this->dashboard('ytd', null);

        $this->assertSame(29520.0, $kbb['kpiProgress']['bts_diterima']['target']);
        $this->assertSame('Sasaran YTD (Anggaran)', $kbb['kpiProgress']['bts_diterima']['target_label']);
        $this->assertSame(41600.0, $kahang['kpiProgress']['bts_diterima']['target']);
        $this->assertSame(71120.0, $combined['kpiProgress']['bts_diterima']['target']);
        $this->assertSame(88677.02, $kbb['periodMetrics']['bts_diterima']);
        $this->assertSame(130530.05, $kahang['periodMetrics']['bts_diterima']);
        $this->assertSame(219207.07, $combined['periodMetrics']['bts_diterima']);
        $this->assertSame(round(88677.02 / 29520 * 100, 2), $kbb['kpiProgress']['bts_diterima']['achievement_percentage']);
        $this->assertSame(round(130530.05 / 41600 * 100, 2), $kahang['kpiProgress']['bts_diterima']['achievement_percentage']);
        $this->assertSame(round(219207.07 / 71120 * 100, 2), $combined['kpiProgress']['bts_diterima']['achievement_percentage']);
        $this->assertEqualsWithDelta(100, $combined['kpiProgress']['bts_diterima']['bar_percentage'], .001);
        $this->assertSame(0.0, $combined['kpiProgress']['bts_diterima']['remaining_percentage']);
        $this->assertSame(
            round(219207.07 / 71120 * 100 - 100, 2),
            $combined['kpiProgress']['bts_diterima']['excess_percentage']
        );

        Carbon::setTestNow('2027-01-01 12:00:00');
        $yearEnd = $this->dashboard('ytd', $this->kbb->id, '2026-12-31');
        $this->assertSame(38400.0, $yearEnd['kpiProgress']['bts_diterima']['target']);
    }

    public function test_missing_and_zero_quantity_targets_do_not_render_misleading_progress(): void
    {
        Carbon::setTestNow('2027-11-04 12:00:00');
        $this->operation($this->kbb, '2027-11-03', ['bts_diterima' => 0, 'bts_diproses' => 0]);
        $this->flowSetting($this->kbb, 'bts_diterima_dan_diproses', 2027, [
            11 => ['green' => 0, 'red' => 0],
        ]);
        $this->flowSetting($this->kbb, 'pengeluaran_cpo', 2027, [
            11 => ['green' => 9000, 'red' => 7200],
        ]);

        $data = $this->dashboard('mtd', $this->kbb->id, '2027-11-03');
        $this->assertNull($data['kpiProgress']['bts_diterima']['target']);
        $this->assertSame('Tiada Sasaran', $data['kpiProgress']['bts_diterima']['status_label']);
        $this->assertSame(0, $data['kpiProgress']['bts_diterima']['bar_percentage']);
        $this->assertSame(900.0, $data['kpiProgress']['pengeluaran_cpo']['target']);
        $this->assertSame(0.0, $data['kpiProgress']['pengeluaran_cpo']['achievement_percentage']);
        $this->assertSame('red', $data['kpiProgress']['pengeluaran_cpo']['tone']);
        $this->assertNull($data['kpiProgress']['jualan_cpo']['target']);
    }

    public function test_leap_day_mtd_proration_uses_29_calendar_days_and_excludes_future_actuals(): void
    {
        Carbon::setTestNow('2028-03-01 12:00:00');
        $this->operation($this->kbb, '2028-02-29', ['bts_diterima' => 2900]);
        $this->operation($this->kbb, '2028-03-01', ['bts_diterima' => 99999]);
        $this->flowSetting($this->kbb, 'bts_diterima_dan_diproses', 2028, [
            2 => ['green' => 2900, 'red' => 2320],
        ]);

        $data = $this->dashboard('mtd', $this->kbb->id, '2028-02-29');

        $this->assertSame(2900.0, $data['periodMetrics']['bts_diterima']);
        $this->assertSame(2900.0, $data['kpiProgress']['bts_diterima']['target']);
        $this->assertSame(100.0, $data['kpiProgress']['bts_diterima']['achievement_percentage']);
    }

    public function test_ratio_targets_are_not_prorated_and_combined_using_authoritative_weights(): void
    {
        $this->loadSnapshot();
        foreach ([
            [$this->kbb, 19.0, 18.0, 22.0],
            [$this->kahang, 20.0, 19.0, 21.0],
        ] as [$mill, $green, $red, $periodTarget]) {
            KpiIndicatorSetting::create([
                'mill_id' => $mill->id,
                'year' => 2026,
                'indicator_code' => 'oer',
                'indicator_name' => 'OER',
                'unit' => '%',
                'evaluation_direction' => 'higher_is_better',
                'green_threshold' => $green,
                'red_threshold' => $red,
                'period_target' => $periodTarget,
                'is_active' => true,
            ]);
        }

        $mtd = $this->dashboard('mtd', null);
        $ytd = $this->dashboard('ytd', null);
        $mtdWeightKbb = 46174.47;
        $mtdWeightKahang = 65272.23;
        $ytdWeightKbb = 88495.02;
        $ytdWeightKahang = 129923.05;

        $this->assertEqualsWithDelta(
            (19 * $mtdWeightKbb + 20 * $mtdWeightKahang) / ($mtdWeightKbb + $mtdWeightKahang),
            $mtd['kpiProgress']['oer']['target'],
            .005
        );
        $this->assertSame(21.41, $ytd['kpiProgress']['oer']['target']);
        $this->assertSame(19.41, $ytd['periodMetrics']['oer']);
    }

    public function test_cumulative_targets_sum_full_prior_months_and_prorate_only_current_month(): void
    {
        $this->flowSetting($this->kbb, 'pengeluaran_cpo', 2027, [
            1 => ['green' => 1000, 'red' => 800],
            2 => ['green' => 2800, 'red' => 2240],
        ]);
        $service = app(KpiEvaluationService::class);
        $evaluate = fn (float $actual, bool $cumulative = true, string $date = '2027-02-14') => $service->evaluate(
            'pengeluaran_cpo', $actual, $this->kbb->id, 2027, 2, true, $date, cumulative: $cumulative
        );
        $green = $evaluate(2400);
        $this->assertSame(2400.0, $green['green_threshold']);
        $this->assertSame(1920.0, $green['red_threshold']);
        $this->assertSame('cumulative', $green['target_type']);
        $this->assertSame('green', $green['status']);
        $this->assertSame('red', $evaluate(1920)['status']);
        $this->assertSame('yellow', $evaluate(2000)['status']);
        $this->assertSame(1400.0, $evaluate(1400, false)['green_threshold']);
        $this->assertSame(3800.0, $evaluate(3800, true, '2027-02-28')['green_threshold']);
        $this->assertSame('grey', $evaluate(999999, true, '2026-02-14')['status']);
        $this->assertSame('grey', $service->evaluate(
            'pengeluaran_cpo', 999999, $this->kahang->id, 2027, 2, true, '2027-02-14', cumulative: true
        )['status']);
    }

    public function test_cumulative_targets_do_not_skip_missing_or_invalid_months_or_use_period_target(): void
    {
        $setting = $this->flowSetting($this->kbb, 'pengeluaran_cpo', 2027, [
            1 => ['green' => 1000, 'red' => 800],
            3 => ['green' => 3000, 'red' => 2400],
        ]);
        $service = app(KpiEvaluationService::class);
        $evaluate = fn () => $service->evaluate(
            'pengeluaran_cpo', 1000000, $this->kbb->id, 2027, 3, true, '2027-03-31', cumulative: true
        );
        $this->assertSame('grey', $evaluate()['status']);
        $this->assertNull($evaluate()['green_threshold']);
        $setting->update(['monthly_targets' => [
            1 => ['green' => 1000, 'red' => 800],
            2 => ['green' => 2000, 'red' => null],
            3 => ['green' => 3000, 'red' => 2400],
        ]]);
        $this->assertSame('grey', $evaluate()['status']);
        $setting->update(['monthly_targets' => [
            1 => ['green' => 1000, 'red' => 800],
            2 => ['green' => 100, 'red' => 200],
            3 => ['green' => 3000, 'red' => 2400],
        ]]);
        $this->assertSame('grey', $evaluate()['status']);
    }

    public function test_ratio_statuses_use_the_displayed_totals_in_all_periods_and_both_mill_settings(): void
    {
        $this->loadSnapshot();
        foreach ([$this->kbb, $this->kahang] as $mill) {
            foreach (['oer', 'ker', 'throughput'] as $code) {
                KpiIndicatorSetting::create([
                    'mill_id' => $mill->id, 'year' => 2026, 'indicator_code' => $code,
                    'indicator_name' => $code, 'unit' => '%', 'evaluation_direction' => 'higher_is_better',
                    'green_threshold' => $mill->code === 'BBJ' ? 100 : 1,
                    'red_threshold' => $mill->code === 'BBJ' ? 99 : .5, 'is_active' => true,
                ]);
            }
        }
        foreach (['harian', 'mtd', 'ytd'] as $period) {
            $data = $this->dashboard($period, null);
            foreach (['oer', 'ker', 'throughput'] as $key) {
                $this->assertSame('red', $data['kpiStatuses'][$key]['tone']);
                $this->assertStringContainsString('KHG: Capai Sasaran', $data['kpiStatuses'][$key]['label']);
                $this->assertStringContainsString('BBJ: Belum Capai Sasaran', $data['kpiStatuses'][$key]['label']);
            }
        }
    }

    public function test_daily_and_mtd_ratio_statuses_include_the_same_production_as_displayed_cards(): void
    {
        app(HistoricalPerformanceImportService::class)->import();
        foreach (['oer' => [25, 20], 'ker' => [6, 5], 'throughput' => [5, 4]] as $code => [$green, $red]) {
            KpiIndicatorSetting::create([
                'mill_id' => $this->kbb->id, 'year' => 2026, 'indicator_code' => $code,
                'indicator_name' => $code, 'unit' => '%', 'evaluation_direction' => 'higher_is_better',
                'green_threshold' => $green, 'red_threshold' => $red, 'is_active' => true,
            ]);
        }
        $this->operation($this->kbb, '2026-11-03', [
            'bts_diproses' => 100, 'produksi_cpo' => 20, 'produksi_pk' => 4, 'jam_operasi' => 10,
        ]);
        $this->operation($this->kbb, '2026-11-03', [
            'bts_diproses' => 0, 'produksi_cpo' => 5, 'produksi_pk' => 2, 'jam_operasi' => 10,
        ]);
        foreach (['harian', 'mtd'] as $period) {
            $data = $this->dashboard($period, $this->kbb->id);
            foreach (['oer' => 25.0, 'ker' => 6.0, 'throughput' => 5.0] as $key => $expected) {
                $this->assertSame($expected, $data['periodMetrics'][$key]);
                $this->assertSame('green', $data['kpiStatuses'][$key]['tone']);
            }
        }
        $empty = $this->dashboard('harian', $this->kbb->id, '2026-11-02');
        $this->assertNull($empty['periodMetrics']['throughput']);
        $this->assertSame(['tone' => 'neutral', 'label' => 'BBJ: Perhatian (Tiada Data)'], $empty['kpiStatuses']['throughput']);
    }

    private function flowSetting(Mill $mill, string $code, int $year, array $months): KpiIndicatorSetting
    {
        return KpiIndicatorSetting::create([
            'mill_id' => $mill->id, 'year' => $year, 'indicator_code' => $code,
            'indicator_name' => $code, 'unit' => 'MT', 'evaluation_direction' => 'higher_is_better',
            'period_target' => 999, 'monthly_targets' => $months, 'is_active' => true,
        ]);
    }

    private function loadSnapshot(): void
    {
        app(HistoricalPerformanceImportService::class)->import();
        $this->operation($this->kbb, '2026-11-03', [
            'bts_diterima' => 46356.47, 'bts_diproses' => 46174.47, 'jam_operasi' => 1584,
            'produksi_cpo' => 9131.21, 'pengeluaran_cpo' => 8642.53,
            'produksi_pk' => 1999.18, 'pengeluaran_pk' => 1449.26,
        ]);
        $this->operation($this->kahang, '2026-11-03', [
            'bts_diterima' => 65879.23, 'bts_diproses' => 65272.23, 'jam_operasi' => 1465.93,
            'produksi_cpo' => 12706.61, 'pengeluaran_cpo' => 12225.28,
            'produksi_pk' => 3334.47, 'pengeluaran_pk' => 3128.40,
        ]);
        $this->operation($this->kbb, '2026-06-22', ['bts_diterima' => 99999, 'bts_diproses' => 99999]);
        $this->operation($this->kbb, '2026-11-04', ['bts_diterima' => 99999, 'bts_diproses' => 99999]);
    }

    private function operation(Mill $mill, string $date, array $values): void
    {
        DB::table('daily_operations')->insert([
            'mill_id' => $mill->id, 'tarikh' => $date, 'stok_cpo' => 777, 'stok_pk' => 333,
            'baki_bts_selepas_diproses' => 111, 'created_at' => now(), 'updated_at' => now(),
            ...$values,
        ]);
    }

    private function dashboard(
        string $period,
        ?int $millId,
        string $date = '2026-11-03',
        bool $assertMarketingSelector = false
    ): array
    {
        $request = Request::create('/dashboard', 'GET', ['period' => $period, 'mill_id' => $millId, 'tarikh' => $date]);
        $request->setUserResolver(fn () => $this->user);
        $mpob = $this->mock(MpobPriceService::class);
        $mpob->shouldReceive('getForDashboard')->andReturn(['products' => [], 'source_available' => false]);
        $view = app(DashboardController::class)->index($request, $mpob);
        $html = $view->render();
        $this->assertStringContainsString('Status Stok Kilang', $html);
        $this->assertStringNotContainsString('bulan capai', $html);
        $this->assertStringNotContainsString('YTD 2026 menggabungkan sejarah bulanan Januari-Jun', $html);
        if ($assertMarketingSelector) {
            $this->assertStringContainsString('Gabungan Kedua-dua Kilang', $html);
            $this->assertStringContainsString('KBB', $html);
            $this->assertStringContainsString('Kahang', $html);
        }
        if ($this->user->isEksekutifPemasaranBts()) {
            $this->assertSame(9, substr_count($html, 'class="mps-card kpi-card"'));
            $selectedValue = $millId === null ? '' : (string) $millId;
            $this->assertMatchesRegularExpression(
                '/<option value="' . preg_quote($selectedValue, '/') . '"\s+selected>/',
                $html
            );
        }

        return $view->getData();
    }

    private function millComparison(int $year, ?int $month): \Illuminate\Support\Collection
    {
        return $this->millComparisonView($year, $month)->getData()['comparison'];
    }

    private function millComparisonView(int $year, ?int $month): \Illuminate\Contracts\View\View
    {
        $request = Request::create('/perbandingan-kilang', 'GET', array_filter([
            'tahun' => $year,
            'bulan' => $month,
        ], fn ($value) => $value !== null));

        return app(MillComparisonController::class)->index($request, app(YtdPerformanceService::class));
    }

    private function analysis(int $year, ?int $millId = null): array
    {
        return $this->analysisView($year, $millId)->getData();
    }

    private function analysisView(int $year, ?int $millId = null): \Illuminate\Contracts\View\View
    {
        $request = Request::create('/analisis-prestasi', 'GET', array_filter([
            'tahun' => $year,
            'mill_id' => $millId,
        ], fn ($value) => $value !== null));
        $request->setUserResolver(fn () => $this->user);

        return app(PerformanceAnalysisController::class)->index(
            $request,
            app(YtdPerformanceService::class)
        );
    }
}
