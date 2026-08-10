<?php

namespace Tests\Feature;

use App\Models\DailyOperation;
use App\Models\Mill;
use App\Models\MpobPriceHistory;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiEvaluationService;
use App\Services\ManagementMonthlyPdfService;
use App\Services\ManagementMonthlyReportPresentationService;
use App\Services\ManagementMonthlyReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManagementMonthlyReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $officer;
    private Mill $kbb;
    private Mill $kahang;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('daily_operations', 'operation_status')) {
            Schema::table('daily_operations', function (Blueprint $table) {
                $table->string('operation_status')->nullable();
            });
        }

        $adminRole = Role::create(['name' => Role::ADMIN, 'label' => 'Admin']);
        $officerRole = Role::create(['name' => Role::PEGAWAI_KILANG, 'label' => 'Pegawai Kilang']);
        $this->kbb = $this->createMill('Kilang Sawit Bukit Bujang', 'BBJ');
        $this->kahang = $this->createMill('Kilang Sawit PPNJ Kahang', 'KHG');
        $this->admin = $this->createUser('admin-fasa3b@test.local', $adminRole);
        $this->officer = $this->createUser('officer-fasa3b@test.local', $officerRole, $this->kbb);
        $this->seedOperations();
    }

    public function test_monthly_report_browser_renders_executive_visual_sections(): void
    {
        foreach ([null, $this->kahang->id, $this->kbb->id] as $millId) {
            $parameters = array_filter(['bulan' => 7, 'tahun' => 2026, 'mill_id' => $millId]);
            $response = $this->actingAs($this->admin)
                ->get(route('laporan-pengurusan-bulanan.index', $parameters))
                ->assertOk()
                ->assertSee('Executive Performance Dashboard')
                ->assertSee('Muat Turun PDF')
                ->assertSee('chart-bts', false)
                ->assertSee('Scorecard KPI Rasmi');

            $html = $response->getContent();
            $this->assertStringContainsString("dailyBarChart('chart-oer'", $html);
            $this->assertStringContainsString("dailyBarChart('chart-ker'", $html);
            $this->assertStringContainsString("lineChart('chart-bts'", $html);
            $this->assertStringContainsString("lineChart('chart-production'", $html);
            $this->assertStringContainsString("lineChart('chart-downtime'", $html);
            $this->assertStringContainsString("{min: 16, max: 20}", $html);
            $this->assertStringContainsString("{min: 2, max: 6}", $html);
            $this->assertStringContainsString("stepSize: .25", $html);
            $this->assertStringContainsString("callback: value => Number(value).toFixed(2)", $html);
        }
    }

    public function test_only_oer_and_ker_daily_pdf_charts_use_bars(): void
    {
        $dataset = app(ManagementMonthlyReportService::class)->generate($this->admin, 2026, 7, $this->kbb->id);
        $charts = app(ManagementMonthlyPdfService::class)->generate(
            $dataset,
            app(ManagementMonthlyReportPresentationService::class)->prepare($dataset)
        )['charts'];

        foreach (['oer', 'ker'] as $chart) {
            $svg = base64_decode(substr($charts[$chart], strlen('data:image/svg+xml;base64,')));
            $this->assertStringContainsString('<rect class="bar"', $svg);
            $this->assertStringNotContainsString('<polyline', $svg);
        }

        foreach (['bts', 'production', 'downtime'] as $chart) {
            $svg = base64_decode(substr($charts[$chart], strlen('data:image/svg+xml;base64,')));
            $this->assertStringContainsString('<polyline', $svg);
            $this->assertStringNotContainsString('<rect class="bar"', $svg);
        }
    }

    public function test_pdf_sections_flow_without_forced_page_per_section(): void
    {
        $html = $this->renderPdfHtml();

        $this->assertStringNotContainsString('.page { page-break-after: always;', $html);
        $this->assertStringNotContainsString('data-pdf-section="production-extraction"', $html);
        $this->assertStringNotContainsString('data-pdf-section="operations-product-flow"', $html);

        foreach (['production', 'extraction', 'operations', 'product-flow'] as $section) {
            $this->assertStringContainsString('data-pdf-section="' . $section . '"', $html);
        }

        $this->assertStringContainsString('class="pdf-section-intro"', $html);
        $this->assertStringContainsString('class="pdf-content-block pdf-chart-pair"', $html);
        $this->assertStringContainsString('class="pdf-content-block pdf-flow-block"', $html);
        $this->assertStringContainsString('.pdf-section-intro,', $html);
        $this->assertStringContainsString('break-inside: avoid;', $html);
    }

    public function test_pdf_is_generated_for_all_mills_kahang_and_kbb(): void
    {
        foreach ([null, $this->kahang->id, $this->kbb->id] as $millId) {
            $parameters = array_filter(['bulan' => 7, 'tahun' => 2026, 'mill_id' => $millId]);
            $response = $this->actingAs($this->admin)
                ->get(route('laporan-pengurusan-bulanan.pdf', $parameters));

            $response->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
            $this->assertGreaterThan(100000, strlen($response->getContent()));
        }
    }

    public function test_comparison_is_only_rendered_for_all_mills(): void
    {
        $allHtml = $this->renderPdfHtml();
        $singleHtml = $this->renderPdfHtml($this->kahang->id);

        $this->assertStringContainsString('data-pdf-section="comparison"', $allHtml);
        $this->assertStringNotContainsString('data-pdf-section="comparison"', $singleHtml);
    }

    public function test_pdf_remains_valid_without_kpi_settings(): void
    {
        $dataset = app(ManagementMonthlyReportService::class)->generate($this->admin, 2026, 7, $this->kbb->id);
        $html = $this->renderPdfHtml($this->kbb->id);
        $report = app(ManagementMonthlyPdfService::class)->generate(
            $dataset,
            app(ManagementMonthlyReportPresentationService::class)->prepare($dataset)
        );

        $this->assertStringContainsString('KPI Belum Ditetapkan', $html);
        $this->assertStringNotContainsString('MPOB', $html);
        $this->assertStringStartsWith('%PDF', $report['content']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $report['charts']['bts']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $report['charts']['production']);
    }

    public function test_operational_issue_renders_but_mpob_never_renders_even_when_history_exists(): void
    {
        $this->createOperation($this->kbb, '2026-07-20', [
            'isu_operasi' => 'Kerosakan conveyor utama',
            'tindakan_pembetulan' => 'Bearing telah diganti',
        ]);
        MpobPriceHistory::create(['category' => 'cpo', 'trade_date' => '2026-07-10', 'price' => 4100]);

        $html = $this->renderPdfHtml($this->kbb->id);

        $this->assertStringContainsString('Isu Operasi Direkodkan', $html);
        $this->assertStringContainsString('Kerosakan conveyor utama', $html);
        $this->assertStringContainsString('Bearing telah diganti', $html);
        $browser = $this->actingAs($this->admin)
            ->get(route('laporan-pengurusan-bulanan.index', ['bulan' => 7, 'tahun' => 2026, 'mill_id' => $this->kbb->id]));

        $this->assertStringNotContainsString('MPOB', $html);
        $this->assertStringNotContainsString('data-pdf-section="mpob"', $html);
        $browser->assertOk()->assertDontSee('MPOB');
    }

    public function test_all_official_kpis_and_product_flow_are_rendered_from_dataset(): void
    {
        $dataset = app(ManagementMonthlyReportService::class)->generate($this->admin, 2026, 7, $this->kbb->id);
        $html = $this->renderPdfHtml($this->kbb->id);

        foreach (KpiEvaluationService::indicatorCatalog() as $indicator) {
            $this->assertStringContainsString(e($indicator['name']), $html);
        }

        $flow = $dataset['overall']['productFlow'];
        $this->assertStringContainsString(number_format((float) $flow['cpo']['opening_stock'], 2), $html);
        $this->assertStringContainsString(number_format((float) $flow['cpo']['closing_stock'], 2), $html);
        $this->assertStringContainsString(number_format((float) $flow['pk']['closing_stock'], 2), $html);
    }

    public function test_single_mill_pdf_does_not_leak_other_mill_data(): void
    {
        $html = $this->renderPdfHtml($this->kbb->id);

        $this->assertStringContainsString($this->kbb->name, $html);
        $this->assertStringNotContainsString($this->kahang->name, $html);
        $this->actingAs($this->officer)
            ->get(route('laporan-pengurusan-bulanan.pdf', [
                'bulan' => 7,
                'tahun' => 2026,
                'mill_id' => $this->kahang->id,
            ]))
            ->assertOk();
        $scopedDataset = app(ManagementMonthlyReportService::class)->generate(
            $this->officer,
            2026,
            7,
            $this->kahang->id
        );
        $this->assertSame($this->kbb->id, $scopedDataset['meta']['scope_mill_id']);
    }

    private function renderPdfHtml(?int $millId = null): string
    {
        $dataset = app(ManagementMonthlyReportService::class)->generate($this->admin, 2026, 7, $millId);
        $presentation = app(ManagementMonthlyReportPresentationService::class)->prepare($dataset);
        $report = app(ManagementMonthlyPdfService::class)->generate($dataset, $presentation);

        return view('laporan-pengurusan-bulanan.pdf', [
            'dataset' => $dataset,
            'presentation' => $presentation,
            'charts' => $report['charts'],
            'logoDataUri' => null,
        ])->render();
    }

    private function seedOperations(): void
    {
        $this->createOperation($this->kbb, '2026-07-01', [
            'bts_diterima' => 1000,
            'bts_diproses' => 900,
            'produksi_cpo' => 180,
            'produksi_pk' => 45,
            'pengeluaran_cpo' => 150,
            'pengeluaran_pk' => 30,
            'stok_cpo_yesterday' => 100,
            'stok_cpo' => 130,
            'stok_pk_yesterday' => 50,
            'stok_pk' => 65,
            'jam_operasi' => 20,
            'downtime_jam' => 1,
        ]);
        $this->createOperation($this->kahang, '2026-07-01', [
            'bts_diterima' => 1500,
            'bts_diproses' => 1400,
            'produksi_cpo' => 300,
            'produksi_pk' => 70,
            'pengeluaran_cpo' => 280,
            'pengeluaran_pk' => 60,
            'stok_cpo_yesterday' => 180,
            'stok_cpo' => 200,
            'stok_pk_yesterday' => 80,
            'stok_pk' => 90,
            'jam_operasi' => 24,
            'downtime_jam' => 2,
        ]);
    }

    private function createOperation(Mill $mill, string $date, array $overrides = []): DailyOperation
    {
        return DailyOperation::create(array_merge([
            'tarikh' => $date,
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
            'pengeluaran_cpo' => 0,
            'pengeluaran_pk' => 0,
            'pk_kcp_to_hopper' => 0,
            'produksi_cpo' => 0,
            'produksi_pk' => 0,
            'stok_cpo' => 0,
            'stok_pk' => 0,
            'stok_cpo_yesterday' => 0,
            'stok_pk_yesterday' => 0,
            'status' => 'submitted',
        ], $overrides));
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
}
