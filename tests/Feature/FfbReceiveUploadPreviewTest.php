<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FfbImportBatch;
use App\Models\FfbSourceCodeMapping;
use App\Models\FfbSourceGroup;
use App\Models\FfbWeighbridgeTicket;
use App\Models\Mill;
use App\Models\Ppk;
use App\Models\Role;
use App\Models\User;
use App\Models\WeighbridgeCentre;
use App\Services\FfbSourceResolver;
use App\Services\FfbBatchConfirmationService;
use App\Services\FfbDailyReceiptService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FfbReceiveUploadPreviewTest extends TestCase
{
    private Mill $bbj;
    private Mill $khg;
    private User $officer;
    private array $files = [];

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        $app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite.database', ':memory:');
            $app['config']->set('database.connections.sqlite.url', null);
            $app['config']->set('session.driver', 'array');
            $app['config']->set('cache.default', 'array');
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->createFixtureSchema();
        Storage::fake('ffb_receive');
        Storage::fake('public');
        $this->bbj = Mill::create(['code' => 'BBJ', 'name' => 'Bukit Bujang', 'location' => 'Johor', 'is_active' => true]);
        $this->khg = Mill::create(['code' => 'KHG', 'name' => 'Kahang', 'location' => 'Johor', 'is_active' => true]);
        $this->officer = $this->user(Role::PEGAWAI_KILANG, $this->bbj);
        $ppk = Ppk::create(['code' => 'P01', 'name' => 'PPK Original']);
        foreach (WeighbridgeCentre::SOURCE_CATEGORIES as $index => $category) {
            $centre = WeighbridgeCentre::create([
                'code' => 'S'.$index, 'name' => 'Source '.$index, 'source_category' => $category,
                'ppk_id' => $index === 1 ? $ppk->id : null,
            ]);
            $group = FfbSourceGroup::create([
                'heading' => 'GROUP '.$index, 'source_category' => $category,
                'weighbridge_centre_id' => $index === 1 ? null : $centre->id,
            ]);
            if ($index === 1) {
                FfbSourceCodeMapping::create([
                    'ffb_source_group_id' => $group->id, 'code' => 'BLOCK',
                    'weighbridge_centre_id' => $centre->id,
                ]);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (Storage::disk('ffb_receive')->allFiles('workbooks') as $path) {
            Storage::disk('ffb_receive')->delete($path);
        }
        parent::tearDown();
    }

    public static function workbookFormats(): array
    {
        return [['Xls', 'tickets.xls'], ['Xlsx', 'tickets.xlsx'], ['Xlsx', 'legacy-name.xls']];
    }

    public static function realSourcePreviewCases(): array
    {
        $cases = [];
        foreach (['Xls', 'Xlsx'] as $format) {
            $cases[$format.' missing masters'] = [$format, false, '2026-11-04'];
            $cases[$format.' mapped but date mismatch'] = [$format, true, '2026-11-04'];
            $cases[$format.' mapped April receipt'] = [$format, true, '2026-04-11'];
        }

        return $cases;
    }

    #[DataProvider('realSourcePreviewCases')]
    public function test_real_source_preview_preserves_names_blocks_dates_and_official_ppks(
        string $format,
        bool $withMasters,
        string $receiptDate,
    ): void {
        FfbSourceCodeMapping::query()->delete();
        FfbSourceGroup::query()->delete();
        WeighbridgeCentre::query()->delete();
        Ppk::query()->delete();
        if ($withMasters) {
            foreach ([
                'BATU-ANAM', 'SEGAMAT', 'LABIS', 'BUKIT-GAMBIR', 'LEDANG-TANGKAK', 'PAGOH',
                'BAKRI', 'MUAR-SELATAN', 'PARIT-SULONG', 'SIMPANG-LIMA', 'SENGGARANG',
                'PARIT-RAJA', 'BATU-PAHAT-TENGAH', 'SRI-MEDAN', 'RENGIT', 'KLUANG',
                'SIMPANG-RENGGAM', 'BENUT', 'AYER-BALOI', 'PEKAN-NANAS', 'PONTIAN-SELATAN',
                'KULAI', 'JOHOR-BAHRU-TIMUR', 'JOHOR-BAHRU-SELATAN', 'KOTA-TINGGI',
                'KOTA-TINGGI-TIMUR', 'PENGERANG', 'MERSING', 'ENDAU',
            ] as $code) {
                Ppk::create(['code' => 'PPK-'.$code, 'name' => 'PPK '.$code]);
            }
            foreach ([
                ['SRC-LADANG-BUKIT-BUJANG', 'LADANG BUKIT BUJANG', 'ppnj_owned'],
                ['SRC-USAHASAMA-PKAYIS-KDSSB', 'LADANG USAHASAMA PKAYIS&KDSSB', 'external_supplier'],
            ] as [$code, $heading, $category]) {
                $source = WeighbridgeCentre::create([
                    'code' => $code, 'name' => $heading, 'source_category' => $category, 'ppk_id' => null,
                ]);
                $group = FfbSourceGroup::create([
                    'heading' => $heading, 'source_category' => $category,
                    'weighbridge_centre_id' => $code === 'SRC-USAHASAMA-PKAYIS-KDSSB' ? null : $source->id,
                ]);
                if ($code === 'SRC-USAHASAMA-PKAYIS-KDSSB') {
                    FfbSourceCodeMapping::create([
                        'ffb_source_group_id' => $group->id,
                        'code' => '99/A',
                        'weighbridge_centre_id' => $source->id,
                    ]);
                }
            }
            $this->assertSame(29, Ppk::where('entity_type', Ppk::OFFICIAL_PPK)->count());
        }
        $mastersBefore = [
            Ppk::orderBy('id')->get()->toJson(), WeighbridgeCentre::orderBy('id')->get()->toJson(),
            FfbSourceGroup::orderBy('id')->get()->toJson(), FfbSourceCodeMapping::orderBy('id')->get()->toJson(),
        ];
        $book = new Spreadsheet();
        foreach (['Supplier', 'Estate'] as $index => $title) {
            $sheet = $index === 0 ? $book->getActiveSheet() : $book->createSheet();
            $sheet->setTitle($title);
            $sheet->setCellValue('A1', 'PERTUBUHAN PELADANG NEGERI JOHOR');
            $sheet->setCellValue('A2', 'DAILY FFB INTAKE');
            $sheet->setCellValue('A3', 'Date :');
            $sheet->setCellValue('D3', 46330);
            $sheet->getStyle('D3')->getNumberFormat()->setFormatCode('d-mmm-yy');
            foreach (['H' => 'D/O Number', 'I' => 'Vehicle No', 'L' => 'NETT. WT'] as $column => $label) {
                $sheet->setCellValue($column.'4', $label);
            }
            foreach (['A' => 'No ', 'B' => 'Code', 'E' => 'Date ', 'F' => 'Ticket No', 'AT' => 'Rej'] as $column => $label) {
                $sheet->setCellValue($column.'5', $label);
            }
            $headingRow = $index === 0 ? 6 : 12;
            $sheet->setCellValue('A'.$headingRow, 'Name :');
            $sheet->setCellValue('C'.$headingRow, $index === 0
                ? 'LADANG USAHASAMA PKAYIS&KDSSB' : 'LADANG BUKIT BUJANG');
            foreach ($index === 0 ? ['99/A'] : ['09A', '04A', '02B'] as $offset => $code) {
                $row = $headingRow + $offset + 1;
                foreach ([
                    'A' => $offset + 1, 'B' => $code, 'E' => 46123,
                    'F' => 'UAT-'.$code, 'H' => 1000 + $row, 'I' => 'UAT 123',
                    'K' => $index === 0 ? 8.8 : [5.16, 6.76, 6.1][$offset],
                ] as $column => $value) {
                    $sheet->setCellValue($column.$row, $value);
                }
                $sheet->getStyle('E'.$row)->getNumberFormat()->setFormatCode('m/d/yyyy');
            }
            $sheet->setCellValue('G20', 'Sub Total :');
            $sheet->setCellValue('K20', $index === 0 ? 8.8 : 18.02);
        }
        $path = tempnam(sys_get_temp_dir(), 'ffb-real-source-');
        $this->files[] = $path;
        try {
            IOFactory::createWriter($book, $format)->save($path);
        } finally {
            $book->disconnectWorksheets();
        }
        $file = new UploadedFile($path, 'uat.'.$format, null, null, true);
        $this->actingAs($this->officer)->post(route('ffb-receive.store'), $this->payload($file, [
            'receipt_date' => $receiptDate, 'control_ticket_count' => 4, 'control_total_mt' => '26.820000',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $batch = FfbImportBatch::sole();
        $dateMismatch = $receiptDate !== '2026-04-11';
        $this->assertSame($withMasters && ! $dateMismatch ? 'ready' : 'needs_review', $batch->status);
        $this->assertSame(4, $batch->ticket_count);
        $this->assertSame('26.820000', $batch->parsed_total_mt);
        $this->assertSame($withMasters && ! $dateMismatch ? 0 : 4, $batch->unresolved_count);
        if ($withMasters && $dateMismatch) {
            $this->assertNotEmpty(app(FfbBatchConfirmationService::class)->summary($batch)['errors']);
        }
        $estateIds = [];
        foreach ($batch->tickets()->orderBy('id')->get() as $ticket) {
            $supplier = $ticket->original_source_code === '99/A';
            $this->assertSame($supplier ? 'Supplier' : 'Estate', $ticket->worksheet);
            $this->assertSame($supplier ? 6 : 12, $ticket->heading_row_number);
            $this->assertSame($supplier ? 'LADANG USAHASAMA PKAYIS&KDSSB' : 'LADANG BUKIT BUJANG', $ticket->original_heading);
            $this->assertSame($ticket->original_source_code, $ticket->raw_values['B']);
            $this->assertSame(46123, $ticket->raw_values['E']);
            $this->assertSame('2026-04-11', $ticket->ticket_date->toDateString());
            $this->assertNull($ticket->ppk_id);
            $expectedReasons = $withMasters ? [] : ['source_mapping:unknown_heading'];
            if ($dateMismatch) {
                $expectedReasons[] = 'ticket_date_differs_from_receipt_date';
            }
            $this->assertSame($expectedReasons, $ticket->review_reasons);
            $this->assertSame($withMasters ? ($supplier ? 'external_supplier' : 'ppnj_owned') : null, $ticket->source_category);
            $this->assertSame($withMasters ? null : 'unknown_heading', $ticket->resolution_snapshot['review_reason']);
            if ($withMasters) {
                $this->assertSame($supplier ? 'SRC-USAHASAMA-PKAYIS-KDSSB' : 'SRC-LADANG-BUKIT-BUJANG',
                    $ticket->resolution_snapshot['source']['code']);
                if (! $supplier) {
                    $estateIds[] = $ticket->weighbridge_centre_id;
                }
            } else {
                $this->assertNull($ticket->weighbridge_centre_id);
            }
        }
        if ($withMasters) {
            $this->assertCount(1, array_unique($estateIds));
        }
        $this->assertSame($mastersBefore, [
            Ppk::orderBy('id')->get()->toJson(), WeighbridgeCentre::orderBy('id')->get()->toJson(),
            FfbSourceGroup::orderBy('id')->get()->toJson(), FfbSourceCodeMapping::orderBy('id')->get()->toJson(),
        ]);
        $this->assertSame(0, DB::table('daily_operations')->count());
        $receipts = app(FfbDailyReceiptService::class);
        $this->assertFalse($receipts->isParallelRun('2026-04-11'));
        $this->assertFalse($receipts->applies('2026-04-11'));
        $this->assertSame('2026-12-01', $receipts->effectiveDate());
    }

    public function test_receipt_reports_reconcile_with_dashboard_and_export_both_formats_without_writes(): void
    {
        $supplier = WeighbridgeCentre::where('source_category', 'external_supplier')->firstOrFail();
        $integrated = WeighbridgeCentre::where('source_category', 'ppnj_ppk_integrated')->firstOrFail();
        $owned = WeighbridgeCentre::where('source_category', 'ppnj_owned')->firstOrFail();
        $batch = $this->dashboardReceipt($this->bbj, '2026-10-28', [
            [$supplier, '10.123456'], [$supplier, '0.876544'], [$integrated, '20.000000'], [$owned, '5.000000'],
        ]);
        $ticket = $batch->tickets()->firstOrFail();
        $ticket->update([
            'ticket_number' => '00123', 'vehicle_number' => 'JAA 1234', 'do_number' => '00007',
            'ticket_date' => '2026-10-27', 'original_heading' => 'Original Supplier Alias',
            'original_source_code' => '=UNTRUSTED()', 'weight_unit' => 'MT',
            'raw_values' => ['AP' => 0, 'AN' => 19.2, 'O' => 40],
        ]);
        $this->reportEvidence($batch);
        $this->dashboardReceipt($this->bbj, '2026-10-29', [], 'confirmed', 'zero_receipt');
        $this->dashboardReceipt($this->bbj, '2026-10-30', [[$supplier, '999.000000']], 'ready');
        $this->dashboardReceipt($this->khg, '2026-10-28', [[$supplier, '777.000000']]);
        $before = [
            DB::table('ffb_import_batches')->orderBy('id')->get()->toJson(),
            DB::table('ffb_weighbridge_tickets')->orderBy('id')->get()->toJson(), AuditLog::count(),
        ];
        $query = ['mill' => 'BBJ', 'type' => 'hq', 'period' => 'monthly', 'month' => '2026-10',
            'categories' => ['ppnj_ppk_integrated', 'ppnj_owned', 'external_supplier']];
        $this->actingAs($this->officer);
        $dashboard = $this->get(route('ffb-receive.dashboard', $query))->assertOk()->viewData('data');
        $response = $this->get(route('ffb-receive.reports.index', $query))->assertOk()
            ->assertSee('Jumlah Mengikut Kategori')->assertSee('Jumlah Mengikut Sumber')->assertSee('Jana PDF')
            ->assertDontSee('Top 5 PPK')->assertDontSee('Sumber Data dan Metodologi')
            ->assertDontSee('Rujukan Batch Disahkan')->assertDontSee('Jenis Laporan');
        $report = $response->viewData('report');
        $this->assertSame($dashboard, $report['data']);
        $this->assertSame(36.0, $report['data']['total_mt']);
        $this->assertSame(20.0, $report['data']['trend'][0]['categories']['ppnj_ppk_integrated']);
        $this->get(route('ffb-receive.reports.pdf', $query))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertSee('%PDF-', false);
        foreach (['hq', 'technical'] as $type) {
            $query['type'] = $type;
            $preview = $this->get(route('ffb-receive.reports.index', $query))->assertOk();
            $preview->assertDontSee('Daftar Terperinci Tiket Timbang');
            $download = $this->get(route('ffb-receive.reports.excel', $query))->assertOk()
                ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $path = tempnam(sys_get_temp_dir(), 'ffb-report-');
            $this->files[] = $path;
            file_put_contents($path, $download->streamedContent());
            $book = IOFactory::load($path);
            $this->assertSame(2, $book->getSheetCount());
            $register = $book->getSheetByName('KBB');
            $this->assertSame('AA', $register->getHighestColumn());
            $this->assertSame('Date', $register->getCell('A6')->getValue());
            $this->assertSame('Bunch Weight', $register->getCell('H6')->getValue());
            $this->assertSame('9', $register->getCell('W7')->getValue());
            $found = false;
            $grandTotals = 0;
            foreach ($register->getRowIterator() as $row) {
                if ($register->getCell('C'.$row->getRowIndex())->getValue() === '00123') {
                    $r = $row->getRowIndex();
                    $found = true;
                    $this->assertSame('00007', $register->getCell('D'.$r)->getValue());
                    $this->assertSame('=UNTRUSTED()', $register->getCell('E'.$r)->getValue());
                    $this->assertSame('s', $register->getCell('E'.$r)->getDataType());
                    $this->assertEquals(0, $register->getCell('X'.$r)->getValue());
                    $this->assertEquals(19.2, $register->getCell('Y'.$r)->getValue());
                    $this->assertEquals(40, $register->getCell('H'.$r)->getValue());
                    $this->assertNull($register->getCell('G'.$r)->getValue());
                }
                if ($register->getCell('A'.$row->getRowIndex())->getValue() === 'Grand Total') {
                    $grandTotals++;
                    $this->assertEquals(36, $register->getCell('F'.$row->getRowIndex())->getValue());
                }
            }
            $this->assertTrue($found);
            $this->assertSame(1, $grandTotals);
            $book->disconnectWorksheets();
        }
        $this->assertSame($before, [
            DB::table('ffb_import_batches')->orderBy('id')->get()->toJson(),
            DB::table('ffb_weighbridge_tickets')->orderBy('id')->get()->toJson(), AuditLog::count(),
        ]);
    }

    public function test_receipt_reports_require_explicit_single_mill_and_enforce_access_on_every_endpoint(): void
    {
        $query = ['mill' => 'KHG', 'period' => 'daily', 'date' => '2026-10-28', 'type' => 'technical'];
        foreach ([Role::PEGAWAI_KILANG, Role::PENGURUS_KILANG] as $role) {
            $this->actingAs($this->user($role, $this->bbj));
            $this->get(route('ffb-receive.reports.index'))->assertOk()->assertViewHas('report', null);
            foreach (['index', 'pdf', 'excel'] as $endpoint) {
                $this->get(route('ffb-receive.reports.'.$endpoint, $query))->assertForbidden();
                $this->get(route('ffb-receive.reports.'.$endpoint, array_merge($query, ['mill' => 'all'])))
                    ->assertSessionHasErrors('mill');
            }
            $this->get(route('ffb-receive.reports.index', array_merge($query, ['mill' => 'BBJ'])))
                ->assertOk()->assertViewHas('report', fn ($report) => $report['data']['has_data'] === false);
        }
        foreach ([Role::ADMIN, Role::PENGURUSAN] as $role) {
            $this->actingAs($this->user($role))->get(route('ffb-receive.reports.index', $query))->assertOk();
        }
        $this->get(route('ffb-receive.reports.pdf'))->assertSessionHasErrors(['mill', 'period']);
        $this->get(route('ffb-receive.reports.index', [
            'mill' => 'BBJ', 'period' => 'range', 'start' => '2026-10-30', 'end' => '2026-10-28',
        ]))->assertSessionHasErrors('end');
        $this->get(route('ffb-receive.reports.index', ['mill' => 'BBJ', 'period' => 'monthly']))
            ->assertSessionHasErrors('month');
        foreach (['index', 'pdf', 'excel'] as $endpoint) {
            $this->get(route('ffb-receive.reports.'.$endpoint, array_merge($query, ['mill' => 'BBJ', 'categories' => ''])))
                ->assertSessionHasErrors('categories');
            $this->get(route('ffb-receive.reports.'.$endpoint, array_merge($query, ['mill' => 'BBJ', 'categories' => ['invalid']])))
                ->assertSessionHasErrors('categories.0');
        }
        $inactive = $this->user(Role::PENGURUSAN);
        $inactive->update(['is_active' => false]);
        foreach (['index', 'pdf', 'excel'] as $endpoint) {
            $this->actingAs($inactive)->get(route('ffb-receive.reports.'.$endpoint, $query))
                ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        }
    }

    public function test_zero_only_receipt_report_has_no_synthetic_tickets_or_quality_values(): void
    {
        $this->dashboardReceipt($this->bbj, '2026-10-29', [], 'confirmed', 'zero_receipt');
        $query = ['mill' => 'BBJ', 'type' => 'technical', 'period' => 'yearly', 'year' => '2026'];
        $response = $this->actingAs($this->officer)->get(route('ffb-receive.reports.index', $query))
            ->assertOk()->assertSee('hari sifar')->assertSee('Tiada tiket timbang.');
        $report = $response->viewData('report');
        $this->assertSame(0.0, $report['data']['total_mt']);
        $this->assertSame(0, $report['data']['ticket_count']);
        $this->assertSame([], $report['tickets']);
        $this->assertSame(1, $report['data']['zero_mill_days']);
        $this->get(route('ffb-receive.reports.index', [
            'mill' => 'BBJ', 'type' => 'hq', 'period' => 'range', 'start' => '2026-10-01', 'end' => '2026-10-28',
        ]))->assertOk()->assertViewHas('report', fn ($report) => $report['data']['has_data'] === false);
    }

    public function test_category_reports_group_child_sources_and_match_quality_by_identity_not_row_position(): void
    {
        $source = WeighbridgeCentre::where('source_category', 'ppnj_ppk_integrated')->firstOrFail();
        $child = WeighbridgeCentre::create([
            'code' => 'CHILD', 'name' => 'Second child', 'source_category' => 'ppnj_ppk_integrated', 'ppk_id' => $source->ppk_id,
        ]);
        $supplier = WeighbridgeCentre::where('source_category', 'external_supplier')->firstOrFail();
        $first = $this->dashboardReceipt($this->bbj, '2026-10-28', [[$source, '2.000000'], [$supplier, '9.000000']]);
        $second = $this->dashboardReceipt($this->bbj, '2026-10-29', [[$child, '3.000000']]);
        $t = $first->tickets()->firstOrFail();
        $t->update(['raw_values' => ['AP' => 0, 'AN' => 18.5, 'AR' => 0.37, 'AS' => 0, 'AD' => 0]]);
        $this->reportEvidence($first);
        $this->reportEvidence($second);
        // A stored row hint may be stale; identity must still select the right evidence row.
        $t->update(['row_number' => 999]);
        $query = ['mill' => 'BBJ', 'period' => 'monthly', 'month' => '2026-10'];
        $this->actingAs($this->officer);
        $report = $this->get(route('ffb-receive.reports.index', $query))->assertOk()->viewData('report');
        $this->assertSame(5.0, $report['data']['total_mt']);
        $this->assertSame(2, $report['data']['ticket_count']);
        $this->assertSame(1, count($report['data']['sources']));
        $this->assertSame(5.0, $report['data']['sources'][0]['mt']);
        $this->get(route('ffb-receive.reports.index', array_merge($query, ['categories' => ['external_supplier']])))
            ->assertOk()->assertViewHas('report', fn ($r) => $r['data']['total_mt'] === 9.0 && $r['data']['ticket_count'] === 1);
        $service = app(\App\Services\FfbReceiveReportService::class);
        $filters = app(\App\Services\FfbDashboardService::class)->period($query, '2026-10-28');
        $full = $service->generate($this->officer, $filters, true);
        $this->assertSame(2, count($full['tickets']));
        $this->assertSame(0.0, $full['tickets'][0]['quality']['X']);
        $this->assertSame(18.5, $full['tickets'][0]['quality']['Y']);
        $this->assertSame(0.37, $full['tickets'][0]['quality']['AA']);
        $this->assertSame($full['tickets'][0]['group_id'], $full['tickets'][1]['group_id']);
        $path = Storage::disk('ffb_receive')->path($first->storage_path);
        $book = IOFactory::load($path);
        $sheet = $book->getActiveSheet();
        foreach (['B', 'E', 'F', 'H', 'I', 'K'] as $column) {
            $sheet->setCellValueExplicit($column.'30', $sheet->getCell($column.'7')->getValue(), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();
        $first->update(['file_sha256' => hash_file('sha256', $path)]);
        $this->get(route('ffb-receive.reports.excel', $query))->assertSessionHasErrors('report');
    }

    private function reportEvidence(FfbImportBatch $batch): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Sheet1');
        foreach ([
            'A2' => 'DAILY FFB INTAKE', 'H4' => 'D/O Number', 'I4' => 'Vehicle No',
            'Q4' => 'Bunch Weight', 'AD4' => 'Bunch Quality', 'AR4' => 'OIL',
            'B5' => 'Code', 'E5' => 'Date', 'F5' => 'Ticket No', 'N5' => 'GOER',
            'P5' => '>25', 'R5' => '>10-25', 'T5' => '>8-10', 'W5' => '>6-8', 'Y5' => '>4-6',
            'AA5' => '>3-4', 'AC5' => '<3', 'AD5' => 'FS', 'AE5' => 'UD', 'AF5' => 'EM',
            'AG5' => 'RB', 'AH5' => 'RB', 'AI5' => 'LS', 'AJ5' => 'DB', 'AL5' => 'DD',
            'AM5' => 'OB', 'AO5' => 'OER', 'AP5' => 'PLTY', 'AQ5' => 'Produced', 'AT5' => 'Rej',
        ] as $cell => $label) {
            $sheet->setCellValue($cell, $label);
        }
        foreach ($batch->tickets()->orderBy('id')->get() as $index => $ticket) {
            $ticket->update([
                'ticket_date' => $ticket->ticket_date ?? $batch->receipt_date,
                'vehicle_number' => $ticket->vehicle_number ?? 'TEST'.$ticket->id,
                'do_number' => $ticket->do_number ?? 'D'.$ticket->id,
                'original_source_code' => $ticket->original_source_code ?? 'C'.$ticket->id,
                'weight_unit' => 'MT',
            ]);
            foreach (array_merge($ticket->raw_values, [
                'B' => $ticket->original_source_code, 'E' => $ticket->ticket_date->toDateString(),
                'F' => $ticket->ticket_number, 'H' => $ticket->do_number, 'I' => $ticket->vehicle_number,
                'K' => $ticket->weight_mt,
            ]) as $column => $value) {
                if (preg_match('/^[A-Z]+$/D', $column)) {
                    $sheet->setCellValueExplicit($column.($index + 7), $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }
        Storage::disk('ffb_receive')->makeDirectory('workbooks');
        $path = 'workbooks/report-'.$batch->id.'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save(Storage::disk('ffb_receive')->path($path));
        $book->disconnectWorksheets();
        $batch->update([
            'storage_disk' => 'ffb_receive', 'storage_path' => $path,
            'file_sha256' => hash_file('sha256', Storage::disk('ffb_receive')->path($path)),
        ]);
    }

    public function test_kahang_aligned_headers_and_incomplete_raw_values_use_matched_evidence(): void
    {
        $source = WeighbridgeCentre::where('source_category', 'external_supplier')->firstOrFail();
        $batch = $this->dashboardReceipt($this->khg, '2026-10-28', [[$source, '4.000000']]);
        $ticket = $batch->tickets()->firstOrFail();
        $ticket->update([
            'ticket_date' => '2026-10-28', 'ticket_number' => '0009', 'do_number' => '0001',
            'vehicle_number' => 'JBB99', 'original_source_code' => 'ORIGINAL', 'weight_unit' => 'MT',
            'raw_values' => ['F' => '4.000000'],
        ]);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Sheet1');
        foreach (['A' => 'Date', 'B' => 'Vehicle No', 'C' => 'Ticket No', 'D' => 'D/O No',
            'E' => 'Code', 'F' => 'Nett WT (MT)', 'G' => 'Unfresh (MT)', 'X' => 'PLTY',
            'Y' => 'OER', 'Z' => 'Reject WT', 'AA' => 'Oil Produced'] as $column => $label) {
            $sheet->mergeCells($column.'6:'.$column.'7')->setCellValue($column.'6', $label);
        }
        $sheet->mergeCells('H6:N6')->setCellValue('H6', 'Bunch Weight');
        $sheet->mergeCells('O6:W6')->setCellValue('O6', 'Bunch Quality');
        foreach (['>25', '>10-25', '>8-10', '>6-8', '>4-6', '>3-4', '<3',
            '1', '2', '3', '4', '5', '6', '7', '8', '9'] as $i => $label) {
            $sheet->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 8).'7', $label, 's');
        }
        foreach (['A' => '28-10-2026', 'B' => 'JBB99', 'C' => '0009', 'D' => '0001',
            'E' => 'ORIGINAL', 'F' => 4, 'G' => 0, 'H' => 20, 'O' => 0, 'X' => 0.5,
            'Y' => 19.1, 'Z' => 0, 'AA' => 0.764] as $column => $value) {
            $sheet->setCellValueExplicit($column.'20', $value, 's');
        }
        Storage::disk('ffb_receive')->makeDirectory('workbooks');
        $path = Storage::disk('ffb_receive')->path('workbooks/kahang.xls');
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();
        $batch->update(['storage_disk' => 'ffb_receive', 'storage_path' => 'workbooks/kahang.xls', 'file_sha256' => hash_file('sha256', $path)]);
        $user = $this->user(Role::PEGAWAI_KILANG, $this->khg);
        $filters = app(\App\Services\FfbDashboardService::class)->period([
            'mill' => 'KHG', 'period' => 'monthly', 'month' => '2026-10',
        ], '2026-10-28');
        $filters['categories'] = ['external_supplier'];
        $report = app(\App\Services\FfbReceiveReportService::class)->generate($user, $filters, true);
        $this->assertSame(0.0, $report['tickets'][0]['quality']['G']);
        $this->assertSame(19.1, $report['tickets'][0]['quality']['Y']);
        $this->assertSame(0.764, $report['tickets'][0]['quality']['AA']);
        $this->assertArrayNotHasKey('I', $report['tickets'][0]['quality']);
        $export = app(\App\Services\FfbReceiveReportExportService::class)->excel($report);
        $this->assertSame('KHG', $export->getActiveSheet()->getTitle());
        $this->assertSame('AA', $export->getActiveSheet()->getHighestColumn());
        $this->assertSame('0009', $export->getActiveSheet()->getCell('C10')->getValue());
        $export->disconnectWorksheets();
    }

    public function test_dashboard_aggregates_canonical_entities_without_mutating_receipts(): void
    {
        $owned = WeighbridgeCentre::where('source_category', 'ppnj_owned')->firstOrFail();
        $supplier = WeighbridgeCentre::where('source_category', 'external_supplier')->firstOrFail();
        $integrated = WeighbridgeCentre::where('source_category', 'ppnj_ppk_integrated')->firstOrFail();
        $child = WeighbridgeCentre::create([
            'code' => 'SECOND-CHILD', 'name' => 'Second child', 'source_category' => 'ppnj_ppk_integrated',
            'ppk_id' => $integrated->ppk_id,
        ]);
        $distinctSupplier = WeighbridgeCentre::create([
            'code' => 'DISTINCT-SUPPLIER', 'name' => $supplier->name, 'source_category' => 'external_supplier',
        ]);
        $first = $this->dashboardReceipt($this->bbj, '2026-10-28', [
            [$supplier, '10.123456'], [$supplier, '0.876544'], [$integrated, '20.000000'],
            [$child, '30.000000'], [$owned, '5.000000'], [$distinctSupplier, '7.000000'],
        ]);
        $this->dashboardReceipt($this->khg, '2026-10-28', [[$supplier, '3.000000']]);
        $this->dashboardReceipt($this->bbj, '2026-10-29', [], 'confirmed', 'zero_receipt');
        foreach (['uploaded', 'ready', 'failed', 'cancelled', 'superseded'] as $status) {
            $this->dashboardReceipt($this->bbj, '2026-10-30', [[$supplier, '999.000000']], $status);
        }
        $before = [
            DB::table('ffb_import_batches')->orderBy('id')->get()->toJson(),
            DB::table('ffb_weighbridge_tickets')->orderBy('id')->get()->toJson(),
            AuditLog::count(),
        ];
        $supplier->update(['name' => 'Renamed master must not replace snapshot']);
        $admin = $this->user(Role::ADMIN);
        $response = $this->actingAs($admin)->get(route('ffb-receive.dashboard', [
            'mill' => 'all', 'period' => 'monthly', 'month' => '2026-10',
        ]))->assertOk()->assertSee('Top 5 Keseluruhan')->assertSee('Top 5 PPK Rasmi')->assertSee('Top 5 Pembekal Luar')
            ->assertSee('Top 5 Ladang Milik PPNJ')->assertDontSee('Renamed master must not replace snapshot');
        $data = $response->viewData('data');
        $this->assertSame(76.0, $data['total_mt']);
        $this->assertSame(7, $data['ticket_count']);
        $this->assertSame(1, $data['zero_mill_days']);
        $this->assertSame(1, $data['zero_dates']);
        $this->assertSame(3, $data['confirmed_days']);
        $this->assertSame(50.0, $data['rankings']['ppnj_ppk_integrated'][0]['mt']);
        $this->assertSame(2, $data['rankings']['ppnj_ppk_integrated'][0]['tickets']);
        $this->assertSame(2, count($data['rankings']['external_supplier']));
        $this->assertSame(14.0, $data['rankings']['external_supplier'][0]['mt']);
        $this->assertSame(7.0, $data['rankings']['external_supplier'][1]['mt']);
        $this->assertEqualsWithDelta(50 / 76 * 100, $data['rankings']['overall'][0]['percentage'], 0.000001);
        $this->assertSame(2, count($data['trend']));
        $this->assertSame(73.0, $data['mills'][0]['mt']);
        $this->assertSame(3.0, $data['mills'][1]['mt']);
        $this->get(route('ffb-receive.dashboard'))->assertOk()
            ->assertViewHas('filters', fn ($filters) => $filters['date'] === '2026-10-29')
            ->assertViewHas('data', fn ($data) => $data['total_mt'] === 0.0 && $data['ticket_count'] === 0 && $data['zero_mill_days'] === 1);
        $this->assertSame($before, [
            DB::table('ffb_import_batches')->orderBy('id')->get()->toJson(),
            DB::table('ffb_weighbridge_tickets')->orderBy('id')->get()->toJson(),
            AuditLog::count(),
        ]);
        $this->assertSame('confirmed', $first->fresh()->status);
    }

    public function test_dashboard_filters_and_permissions_do_not_expose_other_mills(): void
    {
        $supplier = WeighbridgeCentre::where('source_category', 'external_supplier')->firstOrFail();
        $this->dashboardReceipt($this->bbj, '2026-10-28', [[$supplier, '2.000000']]);
        $this->dashboardReceipt($this->khg, '2026-10-30', [[$supplier, '9.000000']]);
        foreach ([Role::PEGAWAI_KILANG, Role::PENGURUS_KILANG] as $role) {
            $this->actingAs($this->user($role, $this->bbj))->get(route('ffb-receive.dashboard'))->assertOk()
                ->assertViewHas('latest', '2026-10-28')
                ->assertViewHas('data', fn ($data) => $data['total_mt'] === 2.0 && count($data['mills']) === 1);
            $this->get(route('ffb-receive.dashboard', ['mill' => 'KHG']))->assertForbidden();
            $this->get(route('ffb-receive.dashboard', ['mill' => 'all']))->assertForbidden();
        }
        foreach ([Role::ADMIN, Role::PENGURUSAN] as $role) {
            $this->actingAs($this->user($role))->get(route('ffb-receive.dashboard', [
                'period' => 'yearly', 'year' => '2026',
            ]))->assertOk()->assertViewHas('data', fn ($data) => $data['total_mt'] === 11.0);
        }
        $this->get(route('ffb-receive.dashboard', [
            'mill' => 'BBJ', 'period' => 'range', 'start' => '2026-10-28', 'end' => '2026-10-28',
        ]))->assertOk()->assertViewHas('data', fn ($data) => $data['total_mt'] === 2.0);
        $this->get(route('ffb-receive.dashboard', [
            'mill' => 'KHG', 'period' => 'daily', 'date' => '2026-10-28',
        ]))->assertOk()->assertSee('Tiada penerimaan disahkan dalam tempoh ini.');
        $this->get(route('ffb-receive.dashboard', [
            'period' => 'range', 'start' => '2026-10-30', 'end' => '2026-10-01',
        ]))->assertSessionHasErrors('end');
        $this->get(route('ffb-receive.dashboard', ['month' => '2026-99']))->assertSessionHasErrors('month');
        $this->get(route('ffb-receive.dashboard', ['mill' => 'BBJ OR 1=1']))->assertSessionHasErrors('mill');
        $inactive = $this->user(Role::PEGAWAI_KILANG, $this->bbj);
        $inactive->update(['is_active' => false]);
        $this->actingAs($inactive)->get(route('ffb-receive.dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_limits_rankings_to_five_and_preserves_distinct_ppk_identities(): void
    {
        $rows = [];
        for ($index = 1; $index <= 7; $index++) {
            $supplier = WeighbridgeCentre::create([
                'code' => 'RANK-'.$index, 'name' => 'Same supplier display name',
                'source_category' => 'external_supplier',
            ]);
            $rows[] = [$supplier, number_format($index, 6, '.', '')];
        }
        $ppk = Ppk::create(['code' => 'DISTINCT-PARENT', 'name' => 'PPK Original']);
        $child = WeighbridgeCentre::create([
            'code' => 'DISTINCT-PPK-CHILD', 'name' => 'Another child',
            'source_category' => 'ppnj_ppk_integrated', 'ppk_id' => $ppk->id,
        ]);
        $otherChild = WeighbridgeCentre::where('source_category', 'ppnj_ppk_integrated')
            ->where('id', '<>', $child->id)->firstOrFail();
        $independentParent = Ppk::create([
            'code' => Ppk::KLUANG_UTARA_CODE,
            'name' => Ppk::KLUANG_UTARA_NAME,
            'entity_type' => Ppk::INDEPENDENT_INTEGRATED_ESTATE,
        ]);
        $independentChild = WeighbridgeCentre::create([
            'code' => 'INDEPENDENT-ESTATE-CHILD', 'name' => 'Independent estate',
            'source_category' => 'ppnj_ppk_integrated', 'ppk_id' => $independentParent->id,
        ]);
        $rows[] = [$child, '0.123456'];
        $rows[] = [$otherChild, '0.000001'];
        $rows[] = [$independentChild, '0.000002'];
        $this->dashboardReceipt($this->bbj, '2026-10-28', $rows);
        $response = $this->actingAs($this->user(Role::PENGURUSAN))
            ->get(route('ffb-receive.dashboard'))->assertOk();
        $data = $response->viewData('data');
        $this->assertSame(28.123459, $data['total_mt']);
        $this->assertSame([7.0, 6.0, 5.0, 4.0, 3.0], array_column($data['rankings']['overall'], 'mt'));
        $this->assertSame(5, count($data['rankings']['external_supplier']));
        $this->assertSame(2, count($data['rankings']['ppnj_ppk_integrated']));
        $this->assertSame(1, count($data['rankings']['independent_integrated_estate']));
        $this->assertSame(
            'ppnj_ppk_integrated:'.$ppk->id,
            $data['rankings']['ppnj_ppk_integrated'][0]['id'],
        );
        $this->assertSame(
            'ppnj_ppk_integrated:'.$independentParent->id,
            $data['rankings']['independent_integrated_estate'][0]['id'],
        );
        $this->assertNotSame(
            $data['rankings']['ppnj_ppk_integrated'][0]['id'],
            $data['rankings']['ppnj_ppk_integrated'][1]['id'],
        );
    }

    public function test_zero_confirmation_is_explicit_locked_and_has_no_excel_or_tickets(): void
    {
        $input = ['mill_id' => $this->bbj->id, 'receipt_date' => '2026-10-28'];
        $this->actingAs($this->officer)->get(route('ffb-receive.zero-confirmation', $input))
            ->assertOk()->assertSee('0.00 MT');
        $this->assertSame(0, FfbImportBatch::count());
        $token = app(FfbBatchConfirmationService::class)->zeroSummary($this->bbj->id, $input['receipt_date'])['token'];
        $this->post(route('ffb-receive.zero-confirm'), $input + ['confirmation_token' => $token])
            ->assertSessionHasErrors('acknowledgement');
        $this->post(route('ffb-receive.zero-confirm'), $input + ['confirmation_token' => $token, 'acknowledgement' => 1])
            ->assertRedirect();
        $batch = FfbImportBatch::sole();
        $this->assertSame('zero_receipt', $batch->receipt_type);
        $this->assertSame('confirmed', $batch->status);
        $this->assertSame('0.00', $batch->confirmed_daily_total_mt);
        $this->assertNull($batch->storage_path);
        $this->assertNull($batch->file_sha256);
        $this->assertSame(0, FfbWeighbridgeTicket::count());
        $this->assertSame(0, DB::table('daily_operations')->count());
        $this->assertSame([], Storage::disk('ffb_receive')->allFiles());
        $this->assertSame(1, AuditLog::where('action', 'ffb_zero_confirmed')->count());
        $this->get(route('ffb-receive.show', $batch))->assertOk()->assertSee('Disahkan / Dikunci');
        $this->post(route('ffb-receive.zero-confirm'), $input + ['confirmation_token' => $token, 'acknowledgement' => 1])
            ->assertSessionHasErrors('confirmation');
        $this->assertSame(1, FfbImportBatch::count());
        $this->assertSame(1, AuditLog::where('action', 'ffb_zero_confirmed')->count());
    }

    public function test_zero_confirmation_respects_roles_mill_scope_and_positive_candidates(): void
    {
        $input = ['mill_id' => $this->bbj->id, 'receipt_date' => '2026-10-28'];
        foreach ([Role::PENGURUSAN, Role::PENGURUS_KILANG] as $role) {
            $this->actingAs($this->user($role, $this->bbj))
                ->get(route('ffb-receive.zero-confirmation', $input))->assertForbidden();
        }
        $this->actingAs($this->officer)->get(route('ffb-receive.zero-confirmation', [
            'mill_id' => $this->khg->id, 'receipt_date' => $input['receipt_date'],
        ]))->assertSessionHasErrors('mill_id');
        $this->post(route('ffb-receive.store'), $this->payload($this->workbook([['weight' => '8.00']])))
            ->assertRedirect();
        $summary = app(FfbBatchConfirmationService::class)->zeroSummary($this->bbj->id, $input['receipt_date']);
        $this->assertNotEmpty($summary['errors']);
        $this->post(route('ffb-receive.zero-confirm'), $input + ['confirmation_token' => $summary['token'], 'acknowledgement' => 1])
            ->assertSessionHasErrors('confirmation');
        $this->assertSame(0, FfbImportBatch::where('status', 'confirmed')->count());
    }

    public function test_daily_creation_requires_confirmation_from_the_effective_date(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-12-01 12:00:00', 'Asia/Kuala_Lumpur'));
        try {
            $input = ['mill_id' => $this->bbj->id, 'tarikh' => '2026-12-01'];
            $this->actingAs($this->officer)->get(route('data-harian.create', $input))
                ->assertOk()->assertSee('Semak Pengesahan BTS')->assertDontSee('id="daily-operation-form"', false);
            $this->post(route('data-harian.store'), $input + ['bts_diterima' => '999.99'])
                ->assertSessionHasErrors('tarikh');
            $this->assertSame(0, DB::table('daily_operations')->count());
            $batch = $this->readyConfirmationBatch('2026-12-01');
            $token = app(FfbBatchConfirmationService::class)->summary($batch)['token'];
            $this->post(route('ffb-receive.confirm', $batch), [
                'confirmation_token' => $token, 'acknowledgement' => 1,
            ])->assertRedirect(route('ffb-receive.show', $batch));
            $this->get(route('data-harian.create', $input))->assertOk()
                ->assertSee('BTS Disahkan / Dikunci')->assertSee('name="bts_diterima" value="3.58" readonly', false)
                ->assertSee('Tidak Operasi (Tutup Sepenuhnya)');
            $this->post(route('data-harian.store'), $this->dailyPayload('2026-12-01', '999.99'))
                ->assertRedirect(route('rekod-harian.index'));
            $operation = DB::table('daily_operations')->whereDate('tarikh', '2026-12-01')->first();
            $batch = FfbImportBatch::sole();
            $this->assertSame(3.58, (float) $operation->bts_diterima);
            $this->assertSame($batch->id, $operation->ffb_import_batch_id);
            $this->assertSame($operation->id, $batch->fresh()->daily_operation_id);
        } finally {
            $this->travelBack();
        }
    }

    public function test_application_and_database_ffb_boundaries_are_fixed_to_december_first(): void
    {
        config()->set('ffb_receive.local_test_effective_date', '2026-10-28');
        $receipts = app(FfbDailyReceiptService::class);

        $this->assertSame('2026-12-01', $receipts->effectiveDate());
        $this->assertFalse($receipts->applies('2026-11-30'));
        $this->assertTrue($receipts->applies('2026-12-01'));
        $this->assertTrue($receipts->isParallelRun('2026-11-05'));
        $this->assertTrue($receipts->isParallelRun('2026-11-30'));
        $this->assertFalse($receipts->isParallelRun('2026-11-04'));

        $databaseGuard = file_get_contents(database_path('migrations/2026_11_03_000005_lock_confirmed_ffb_receipts.php'));
        $this->assertIsString($databaseGuard);
        $this->assertStringContainsString("NEW.tarikh >= '2026-12-01'", $databaseGuard);
        $this->assertStringContainsString("OLD.tarikh < '2026-12-01' AND NEW.tarikh >= '2026-12-01'", $databaseGuard);
        $orphanGuard = file_get_contents(database_path('migrations/2026_11_05_000001_prevent_orphan_ffb_confirmations.php'));
        $this->assertIsString($orphanGuard);
        $this->assertSame(2, substr_count($orphanGuard, "NEW.receipt_date >= '2026-12-01'"));
        $this->assertSame(1, substr_count($orphanGuard, "NEW.tarikh >= '2026-12-01'"));
        $this->assertSame(2, substr_count($orphanGuard, "NEW.receipt_date BETWEEN '2026-11-05' AND '2026-11-30'"));
        $this->assertStringContainsString('NEW.ffb_import_batch_id IS NOT NULL', $orphanGuard);
        $this->assertStringContainsString("IF NEW.tarikh >= '2026-12-01' THEN", $orphanGuard);
    }

    public function test_november_30_keeps_legacy_daily_receipt_input_without_a_confirmed_batch(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-30 12:00:00', 'Asia/Kuala_Lumpur'));
        $request = \Illuminate\Http\Request::create('/data-harian', 'POST', [
            'mill_id' => $this->bbj->id, 'tarikh' => '2026-11-30',
            'shift' => 'Harian', 'bts_diterima' => '123.45',
        ]);

        $batch = app(FfbDailyReceiptService::class)->prepare($request);

        $this->assertNull($batch);
        $this->assertSame('123.45', $request->input('bts_diterima'));
    }

    public function test_daily_operation_route_saves_november_30_with_manual_legacy_receipt(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-30 12:00:00', 'Asia/Kuala_Lumpur'));
        $this->actingAs($this->officer)->post(route('data-harian.store'), [
            'mill_id' => $this->bbj->id,
            'tarikh' => '2026-11-30',
            'operation_status' => 'Operasi',
            'bts_diterima' => '10.00',
            'bts_diproses' => '8.00',
            'jam_operasi' => '8.00',
            'downtime_jam' => '1.00',
            'pengeluaran_cpo' => '0.00',
            'pengeluaran_pk' => '0.00',
            'pk_kcp_to_hopper' => '0.00',
            'produksi_cpo' => '0.00',
            'produksi_pk' => '0.00',
            'stok_cpo_yesterday' => '50.00',
            'stok_pk_yesterday' => '20.00',
            'stok_cpo' => '50.00',
            'stok_pk' => '20.00',
            'baki_bts_semalam' => '0.00',
            'baki_bts_selepas_diproses' => '2.00',
        ])->assertRedirect(route('rekod-harian.index'))
            ->assertSessionHas('success', 'Rekod harian berjaya disimpan.');

        $operation = DB::table('daily_operations')->whereDate('tarikh', '2026-11-30')->first();
        $this->assertNotNull($operation);
        $this->assertSame(10.0, (float) $operation->bts_diterima);
        $this->assertNull($operation->ffb_import_batch_id);
    }

    public function test_november_excel_confirmation_before_daily_submission_never_links_training_batch(): void
    {
        $date = '2026-11-30';
        $this->travelTo(\Carbon\Carbon::parse($date.' 12:00:00', 'Asia/Kuala_Lumpur'));
        $batch = $this->readyConfirmationBatch($date);
        $token = app(FfbBatchConfirmationService::class)->summary($batch)['token'];

        $this->actingAs($this->officer)->post(route('ffb-receive.confirm', $batch), [
            'acknowledgement' => 1, 'confirmation_token' => $token,
        ])->assertRedirect(route('ffb-receive.show', $batch))->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->daily_operation_id);
        $this->assertSame(0, DB::table('daily_operations')->count());
        $this->assertTrue(app(\App\Services\FfbReceiveReportService::class)->generate(
            $this->officer,
            app(\App\Services\FfbDashboardService::class)->period(['period' => 'daily', 'date' => $date, 'mill' => 'BBJ'], $date)
                + ['mill' => 'BBJ', 'categories' => ['ppnj_owned']],
        )['parallel_run_notice']);

        $this->actingAs($this->officer)->post(route('data-harian.store'), $this->dailyPayload($date, '123.45'))
            ->assertRedirect(route('rekod-harian.index'));
        $operation = DB::table('daily_operations')->whereDate('tarikh', $date)->first();
        $this->assertSame(123.45, (float) $operation->bts_diterima);
        $this->assertNull($operation->ffb_import_batch_id);
        $this->assertNull($batch->fresh()->daily_operation_id);
    }

    public function test_november_excel_confirmation_after_daily_operation_preserves_manual_bts(): void
    {
        $date = '2026-11-30';
        $this->travelTo(\Carbon\Carbon::parse($date.' 12:00:00', 'Asia/Kuala_Lumpur'));
        $batch = $this->readyConfirmationBatch($date);
        DB::table('daily_operations')->insert([
            'mill_id' => $this->bbj->id, 'tarikh' => $date, 'shift' => 'Harian',
            'bts_diterima' => '12.34', 'oer' => '19.99', 'ker' => '4.99',
        ]);
        $dailyBefore = DB::table('daily_operations')->get()->toJson();
        $summary = app(FfbBatchConfirmationService::class)->summary($batch);
        $this->assertNotContains(
            'Pengesahan FFB tidak dibenarkan kerana rekod operasi harian bagi kilang dan tarikh ini sudah wujud. Rekod sedia ada tidak boleh dipautkan secara retroaktif.',
            $summary['errors'],
        );
        $this->actingAs($this->officer)->post(route('ffb-receive.confirm', $batch), [
            'acknowledgement' => 1, 'confirmation_token' => $summary['token'],
        ])->assertRedirect(route('ffb-receive.show', $batch))->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->daily_operation_id);
        $this->assertSame($dailyBefore, DB::table('daily_operations')->get()->toJson());
        $this->assertSame(1, AuditLog::where('action', 'ffb_confirmed')->count());
    }

    public function test_november_zero_confirmation_after_daily_operation_is_allowed_without_linking(): void
    {
        $date = '2026-11-30';
        $this->travelTo(\Carbon\Carbon::parse($date.' 12:00:00', 'Asia/Kuala_Lumpur'));
        $input = ['mill_id' => $this->bbj->id, 'receipt_date' => $date];
        DB::table('daily_operations')->insert([
            'mill_id' => $this->bbj->id, 'tarikh' => $date, 'shift' => 'Harian',
            'bts_diterima' => '12.34', 'oer' => '19.99', 'ker' => '4.99',
        ]);
        $dailyBefore = DB::table('daily_operations')->get()->toJson();
        $summary = app(FfbBatchConfirmationService::class)->zeroSummary($this->bbj->id, $input['receipt_date']);
        $this->assertSame([], $summary['errors']);
        $this->actingAs($this->officer)->post(route('ffb-receive.zero-confirm'), $input + [
            'confirmation_token' => $summary['token'], 'acknowledgement' => 1,
        ])->assertRedirect();

        $batch = FfbImportBatch::sole();
        $this->assertSame('confirmed', $batch->status);
        $this->assertNull($batch->daily_operation_id);
        $this->assertSame($dailyBefore, DB::table('daily_operations')->get()->toJson());
        $this->assertSame(1, AuditLog::where('action', 'ffb_zero_confirmed')->count());
    }

    public function test_november_manual_bts_submission_is_not_replaced_or_linked_to_confirmed_training_batch(): void
    {
        $date = '2026-11-30';
        $this->travelTo(\Carbon\Carbon::parse($date.' 12:00:00', 'Asia/Kuala_Lumpur'));
        $input = ['mill_id' => $this->bbj->id, 'receipt_date' => $date];
        $summary = app(FfbBatchConfirmationService::class)->zeroSummary($this->bbj->id, $input['receipt_date']);
        $this->actingAs($this->officer)->post(route('ffb-receive.zero-confirm'), $input + [
            'confirmation_token' => $summary['token'], 'acknowledgement' => 1,
        ])->assertRedirect();

        $this->actingAs($this->officer)->post(route('data-harian.store'), $this->dailyPayload($date, '123.45'))
            ->assertRedirect(route('rekod-harian.index'));
        $operation = DB::table('daily_operations')->whereDate('tarikh', $date)->first();
        $this->assertSame(123.45, (float) $operation->bts_diterima);
        $this->assertNull($operation->ffb_import_batch_id);
        $this->assertSame(1, DB::table('daily_operations')->count());
    }

    public function test_november_manual_bts_create_form_stays_editable_with_confirmed_training_batch(): void
    {
        $date = '2026-11-30';
        $this->travelTo(\Carbon\Carbon::parse($date.' 12:00:00', 'Asia/Kuala_Lumpur'));
        $input = ['mill_id' => $this->bbj->id, 'receipt_date' => $date];
        $summary = app(FfbBatchConfirmationService::class)->zeroSummary($this->bbj->id, $input['receipt_date']);
        $this->actingAs($this->officer)->post(route('ffb-receive.zero-confirm'), $input + [
            'confirmation_token' => $summary['token'], 'acknowledgement' => 1,
        ])->assertRedirect();
        $this->actingAs($this->officer)->get(route('data-harian.create', [
            'mill_id' => $this->bbj->id, 'tarikh' => $date,
        ]))->assertOk()->assertSee('name="bts_diterima"', false)
            ->assertDontSee('name="bts_diterima" value="0.00" readonly', false)
            ->assertDontSee('BTS Disahkan / Dikunci');
    }

    public function test_kahang_parallel_run_zero_confirmation_is_labeled_and_stays_unlinked(): void
    {
        $date = '2026-11-05';
        $this->travelTo(\Carbon\Carbon::parse($date.' 12:00:00', 'Asia/Kuala_Lumpur'));
        $officer = $this->user(Role::PEGAWAI_KILANG, $this->khg);
        $summary = app(FfbBatchConfirmationService::class)->zeroSummary($this->khg->id, $date);

        $this->actingAs($officer)->get(route('ffb-receive.zero-confirmation', [
            'mill_id' => $this->khg->id, 'receipt_date' => $date,
        ]))->assertOk()->assertSee('PARALLEL RUN');
        $this->post(route('ffb-receive.zero-confirm'), [
            'mill_id' => $this->khg->id, 'receipt_date' => $date,
            'confirmation_token' => $summary['token'], 'acknowledgement' => 1,
        ])->assertRedirect();

        $batch = FfbImportBatch::sole();
        $this->assertSame($this->khg->id, $batch->mill_id);
        $this->assertSame('confirmed', $batch->status);
        $this->assertNull($batch->daily_operation_id);
        $this->assertSame(0, DB::table('daily_operations')->count());
    }

    public function test_parallel_run_batches_remain_outside_the_dashboard_kpi_data_source(): void
    {
        $dashboardSource = file_get_contents(app_path('Http/Controllers/DashboardController.php'));
        $this->assertIsString($dashboardSource);
        $this->assertStringContainsString('$baseQuery = DailyOperation::query();', $dashboardSource);
        $this->assertStringNotContainsString('FfbImportBatch', $dashboardSource);
    }

    public function test_december_excel_confirmation_is_blocked_when_daily_operation_already_exists(): void
    {
        $date = '2026-12-01';
        $this->travelTo(\Carbon\Carbon::parse($date.' 12:00:00', 'Asia/Kuala_Lumpur'));
        $batch = $this->readyConfirmationBatch($date);
        DB::table('daily_operations')->insert([
            'mill_id' => $this->bbj->id, 'tarikh' => $date, 'shift' => 'Harian',
            'bts_diterima' => '12.34', 'oer' => '19.99', 'ker' => '4.99',
        ]);
        $summary = app(FfbBatchConfirmationService::class)->summary($batch);
        $this->assertContains(
            'Pengesahan FFB tidak dibenarkan kerana rekod operasi harian bagi kilang dan tarikh ini sudah wujud. Rekod sedia ada tidak boleh dipautkan secara retroaktif.',
            $summary['errors'],
        );
        foreach ([$this->officer, $this->user(Role::ADMIN)] as $actor) {
            $this->actingAs($actor)->post(route('ffb-receive.confirm', $batch), [
                'acknowledgement' => 1, 'confirmation_token' => $summary['token'],
            ])->assertSessionHasErrors('confirmation');
        }
        $this->assertSame('ready', $batch->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'ffb_confirmed')->count());
    }

    public function test_december_zero_confirmation_is_blocked_when_daily_operation_already_exists(): void
    {
        $date = '2026-12-01';
        $this->travelTo(\Carbon\Carbon::parse($date.' 12:00:00', 'Asia/Kuala_Lumpur'));
        DB::table('daily_operations')->insert([
            'mill_id' => $this->bbj->id, 'tarikh' => $date, 'shift' => 'Harian',
            'bts_diterima' => '12.34', 'oer' => '19.99', 'ker' => '4.99',
        ]);
        $input = ['mill_id' => $this->bbj->id, 'receipt_date' => $date];
        $summary = app(FfbBatchConfirmationService::class)->zeroSummary($this->bbj->id, $date);
        $this->assertNotEmpty($summary['errors']);
        foreach ([$this->officer, $this->user(Role::ADMIN)] as $actor) {
            $this->actingAs($actor)->post(route('ffb-receive.zero-confirm'), $input + [
                'confirmation_token' => $summary['token'], 'acknowledgement' => 1,
            ])->assertSessionHasErrors('confirmation');
        }
        $this->assertSame(0, FfbImportBatch::count());
        $this->assertSame(0, AuditLog::where('action', 'ffb_zero_confirmed')->count());
    }

    public function test_parallel_run_batches_are_labeled_in_report_previews_and_exports(): void
    {
        $date = '2026-11-10';
        $this->dashboardReceipt($this->bbj, $date, [], 'confirmed', 'zero_receipt');
        $query = [
            'mill' => 'BBJ', 'period' => 'daily', 'date' => $date,
            'categories' => ['ppnj_owned'],
        ];
        $this->actingAs($this->officer)
            ->get(route('ffb-receive.dashboard', $query))
            ->assertOk()->assertSee('Parallel Run')->assertSee('bukan KPI operasi rasmi');
        $this->get(route('ffb-receive.reports.index', $query))
            ->assertOk()->assertSee('PARALLEL RUN')->assertSee('bukan data KPI operasi rasmi');
        $report = app(\App\Services\FfbReceiveReportService::class)->generate(
            $this->officer,
            app(\App\Services\FfbDashboardService::class)->period($query, $date)
                + ['categories' => ['ppnj_owned']],
        );
        $this->assertTrue($report['parallel_run_notice']);
        $this->assertStringContainsString('PARALLEL RUN', app(\App\Services\FfbReceiveReportExportService::class)
            ->excel($report)->getActiveSheet()->getCell('A5')->getValue());
        $this->get(route('ffb-receive.reports.pdf', $query))->assertOk()->assertSee('%PDF-', false);
    }

    #[DataProvider('workbookFormats')]
    public function test_upload_persists_all_categories_snapshots_private_file_and_audit(string $format, string $filename): void
    {
        $this->actingAs($this->officer);
        $file = $this->workbook([
            ['heading' => 'GROUP 0', 'number' => '00001', 'weight' => '1.234567'],
            ['heading' => 'GROUP 1', 'number' => '00002', 'weight' => '2.000000'],
            ['heading' => 'GROUP 2', 'number' => '00003', 'weight' => '3.000000'],
        ], $format, $filename);
        $hash = hash_file('sha256', $file->getPathname());
        $this->post(route('ffb-receive.store'), $this->payload($file, [
            'control_ticket_count' => 3, 'control_total_mt' => '6.234567',
            'status' => 'confirmed', 'confirmed_by' => $this->officer->id,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $batch = FfbImportBatch::sole();
        $this->assertSame('ready', $batch->status);
        $this->assertSame($format, $batch->detected_format);
        $this->assertSame($filename, $batch->original_filename);
        $this->assertSame($hash, $batch->file_sha256);
        $this->assertSame(3, $batch->ticket_count);
        $this->assertSame('6.234567', $batch->parsed_total_mt);
        $this->assertTrue($batch->has_complete_weight_total);
        $this->assertSame(0, $batch->unresolved_count);
        $this->assertNull($batch->confirmed_at);
        $this->assertNull($batch->confirmed_by);
        $this->assertNull($batch->daily_operation_id);
        $this->assertNull($batch->synced_at);
        $this->assertSame(64, strlen($batch->preview_hash));
        $this->assertSame('ffb_receive', $batch->storage_disk);
        $this->assertSame(storage_path('app/private/ffb-receive'), config('filesystems.disks.ffb_receive.root'));
        $this->assertFalse(config('filesystems.disks.ffb_receive.serve'));
        $this->assertTrue(config('filesystems.disks.ffb_receive.throw'));
        Storage::disk('ffb_receive')->assertExists($batch->storage_path);
        $this->assertSame($hash, hash('sha256', Storage::disk('ffb_receive')->get($batch->storage_path)));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, DB::table('daily_operations')->count());
        $this->assertSame(['ffb_uploaded', 'ffb_previewed'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame($this->officer->id, AuditLog::first()->user_id);
        $ticket = $batch->tickets()->where('ticket_number', '00002')->sole();
        $this->assertSame('BLOCK', $ticket->original_source_code);
        $this->assertSame('00002', $ticket->raw_values['F']);
        $this->assertSame('ppnj_ppk_integrated', $ticket->source_category);
        $this->assertNotNull($ticket->weighbridge_centre_id);
        $this->assertSame('PPK Original', $ticket->resolution_snapshot['ppk']['name']);
        $this->assertSame($ticket->ppk_id, $ticket->resolution_snapshot['ppk']['id']);

        Ppk::first()->update(['name' => 'PPK Renamed']);
        WeighbridgeCentre::whereKey($ticket->weighbridge_centre_id)->update(['name' => 'Source Renamed']);
        $this->get(route('ffb-receive.show', $batch))->assertOk()
            ->assertSee('KBB')->assertSee('6.23 MT')->assertSee('Ladang Milik PPNJ')
            ->assertSee('BTS Bersepadu PPNJ–PPK')->assertSee('Pembekal Luar')
            ->assertSee('PPK Original')->assertDontSee('PPK Renamed')->assertDontSee('Source Renamed')
            ->assertSee('00001')->assertSee('00002')->assertSee('00003');
        $this->get(route('ffb-receive.index'))->assertOk()
            ->assertSee('FFB Receive')->assertSee('KBB')->assertDontSee('>BBJ<', false);
        $this->assertSame('BBJ', $this->bbj->fresh()->code);
    }

    public function test_invalid_duplicates_unknown_sources_and_date_mismatch_remain_visible(): void
    {
        $this->actingAs($this->officer)->post(route('ffb-receive.store'), $this->payload($this->workbook([
            ['number' => 'DUP', 'weight' => 1],
            ['number' => 'dup', 'weight' => 'bad', 'date' => 'not-a-date'],
            ['number' => 'UNKNOWN', 'heading' => '<script>unknown</script>', 'weight' => 3, 'date' => '2026-10-27'],
            ['number' => '', 'code' => '', 'do' => '', 'vehicle' => '', 'weight' => 4],
        ])))->assertSessionHasNoErrors()->assertRedirect();

        $batch = FfbImportBatch::sole();
        $this->assertSame('needs_review', $batch->status);
        $this->assertSame(4, $batch->ticket_count);
        $this->assertSame(4, $batch->tickets()->count());
        $this->assertSame(4, $batch->unresolved_count);
        $this->assertSame('8.000000', $batch->parsed_total_mt);
        $this->assertFalse($batch->has_complete_weight_total);
        $invalid = $batch->tickets()->where('ticket_number', 'dup')->sole();
        $this->assertNull($invalid->weight_mt);
        $this->assertNull($invalid->ticket_date);
        $this->assertSame('bad', $invalid->raw_values['K']);
        $this->assertContains('duplicate_ticket_number', $invalid->review_reasons);
        $this->get(route('ffb-receive.show', $batch))->assertOk()
            ->assertSee('source_mapping:unknown_heading')->assertSee('duplicate_ticket_number')
            ->assertSee('invalid_nett_weight')->assertSee('invalid_ticket_date')
            ->assertSee('ticket_date_differs_from_receipt_date')->assertSee('missing_ticket_number')
            ->assertDontSee('<script>unknown</script>', false)->assertSee('&lt;script&gt;unknown&lt;/script&gt;', false);
    }

    public function test_unlabelled_weights_need_unit_and_workbook_units_override_caller(): void
    {
        $this->actingAs($this->officer);
        $this->post(route('ffb-receive.store'), $this->payload($this->workbook([['weight' => 1000]], label: null), [
            'confirmed_weight_unit' => '',
        ]))->assertRedirect();
        $batch = FfbImportBatch::sole();
        $this->assertSame('needs_review', $batch->status);
        $this->assertContains('unconfirmed_weight_unit', $batch->tickets()->sole()->review_reasons);
        $this->assertNull($batch->tickets()->sole()->weight_mt);

        $this->post(route('ffb-receive.store'), $this->payload($this->workbook([['weight' => 1000]], label: 'KG'), [
            'confirmed_weight_unit' => 'MT',
        ]))->assertRedirect();
        $ticket = FfbImportBatch::latest('id')->first()->tickets()->sole();
        $this->assertSame('1.000000', $ticket->weight_mt);
        $this->assertSame('workbook', $ticket->weight_unit_origin);
    }

    public function test_control_mismatches_block_ready_and_empty_workbooks_require_review(): void
    {
        $this->actingAs($this->officer);
        $this->post(route('ffb-receive.store'), $this->payload($this->workbook([['weight' => 2]]), [
            'control_ticket_count' => 2, 'control_total_mt' => 3,
        ]))->assertRedirect();
        $batch = FfbImportBatch::sole();
        $this->assertSame('needs_review', $batch->status);
        $this->assertSame(-1, $batch->reconciliation_evidence['ticket_count_difference']);
        $this->assertSame('-1.000000', $batch->reconciliation_evidence['total_mt_difference']);
        $this->get(route('ffb-receive.show', $batch))->assertOk()->assertSee('tidak sepadan');

        $this->post(route('ffb-receive.store'), $this->payload($this->workbook([])))->assertRedirect();
        $empty = FfbImportBatch::latest('id')->first();
        $this->assertSame('needs_review', $empty->status);
        $this->assertSame(0, $empty->ticket_count);
        $this->assertSame(0, $empty->tickets()->count());
    }

    public function test_unrepresentable_identifiers_and_weights_preserve_raw_candidates(): void
    {
        $this->actingAs($this->officer)->post(route('ffb-receive.store'), $this->payload($this->workbook([
            ['number' => str_repeat('T', 256), 'weight' => '1000000000'],
        ])))->assertRedirect();
        $batch = FfbImportBatch::sole();
        $ticket = $batch->tickets()->sole();
        $this->assertSame('needs_review', $batch->status);
        $this->assertNull($ticket->ticket_number);
        $this->assertNull($ticket->weight_mt);
        $this->assertSame(str_repeat('T', 256), $ticket->raw_values['F']);
        $this->assertSame('1000000000', $ticket->raw_values['K']);
        $this->assertContains('ticket_number_exceeds_255_characters', $ticket->review_reasons);
        $this->assertContains('weight_exceeds_database_range', $ticket->review_reasons);
    }

    public function test_failed_persistence_rolls_back_partial_tickets_and_exposes_failed_state(): void
    {
        FfbWeighbridgeTicket::creating(function (FfbWeighbridgeTicket $ticket): void {
            if ($ticket->ticket_number === 'FAIL') {
                throw new RuntimeException('Deliberate internal failure with private diagnostic detail');
            }
        });
        try {
            $this->actingAs($this->officer)->post(route('ffb-receive.store'), $this->payload($this->workbook([
                ['number' => 'OK'], ['number' => 'FAIL'],
            ])))->assertRedirect()->assertSessionHas('error');
            $batch = FfbImportBatch::sole();
            $this->assertSame('failed', $batch->status);
            $this->assertSame(0, $batch->tickets()->count());
            $this->assertNull($batch->ticket_count);
            Storage::disk('ffb_receive')->assertExists($batch->storage_path);
            $this->assertSame(['ffb_uploaded', 'ffb_import_failed'], AuditLog::orderBy('id')->pluck('action')->all());
            $this->get(route('ffb-receive.show', $batch))->assertOk()->assertSee('Import gagal')
                ->assertDontSee('private diagnostic detail');
        } finally {
            FfbWeighbridgeTicket::flushEventListeners();
        }
    }

    public function test_parser_resolution_failure_is_logged_as_failed_with_original_file_retained(): void
    {
        $resolver = \Mockery::mock(FfbSourceResolver::class);
        $resolver->shouldReceive('resolveForMill')->once()->andThrow(new RuntimeException('Resolver unavailable'));
        $this->app->instance(FfbSourceResolver::class, $resolver);
        $this->actingAs($this->officer)->post(route('ffb-receive.store'), $this->payload($this->workbook([[]])))
            ->assertRedirect()->assertSessionHas('error');
        $batch = FfbImportBatch::sole();
        $this->assertSame('failed', $batch->status);
        $this->assertSame(0, $batch->tickets()->count());
        $this->assertSame(0, DB::table('daily_operations')->count());
        $this->assertSame('ffb_import_failed', AuditLog::latest('id')->first()->action);
        Storage::disk('ffb_receive')->assertExists($batch->storage_path);
    }

    public function test_storage_and_daily_rounding_limits_are_reviewed_not_silently_overflowed(): void
    {
        $this->actingAs($this->officer);
        $this->post(route('ffb-receive.store'), $this->payload($this->workbook([
            ['weight' => '99999999.999999'],
        ])))->assertRedirect();
        $batch = FfbImportBatch::sole();
        $this->assertSame('needs_review', $batch->status);
        $this->get(route('ffb-receive.show', $batch))->assertOk()->assertSee('melebihi julat BTS Diterima');
        $this->post(route('ffb-receive.store'), $this->payload($this->workbook([
            ['weight' => '0.0000001'],
        ])))->assertRedirect();
        $ticket = FfbImportBatch::latest('id')->first()->tickets()->sole();
        $this->assertNull($ticket->weight_mt);
        $this->assertContains('weight_below_storage_precision', $ticket->review_reasons);
    }

    public function test_invalid_content_extension_size_and_archive_are_rejected_before_batch_creation(): void
    {
        $this->actingAs($this->officer);
        foreach ([
            UploadedFile::fake()->createWithContent('fake.xlsx', '<html>not an Excel workbook</html>'),
            UploadedFile::fake()->create('oversized.xlsx', 10241),
            $this->workbook([[]], filename: 'wrong.csv'),
        ] as $file) {
            $this->post(route('ffb-receive.store'), $this->payload($file))
                ->assertSessionHasErrors('workbook');
        }
        $largeArchive = $this->workbook([[]]);
        $zip = new \ZipArchive();
        $zip->open($largeArchive->getPathname());
        $zip->addFromString('xl/oversized.xml', str_repeat('0', 101 * 1024 * 1024));
        $zip->close();
        $this->post(route('ffb-receive.store'), $this->payload($largeArchive))->assertSessionHasErrors('workbook');
        $this->assertSame(0, FfbImportBatch::count());
        $this->assertSame([], Storage::disk('ffb_receive')->allFiles());
    }

    public function test_officer_cannot_upload_or_read_other_mill_and_input_cannot_change_daily_data(): void
    {
        $other = $this->batch($this->khg);
        $this->actingAs($this->officer);
        $this->get(route('ffb-receive.show', $other))->assertForbidden();
        $this->post(route('ffb-receive.store'), $this->payload($this->workbook([[]]), [
            'mill_id' => $this->khg->id,
        ]))->assertSessionHasErrors('mill_id');
        $this->get(route('ffb-receive.index'))->assertOk()->assertDontSee('OTHER-MILL.xlsx');
        $this->assertSame(1, FfbImportBatch::count());
        $this->assertSame(0, DB::table('daily_operations')->count());
    }

    public static function viewerRoles(): array
    {
        return [
            'management all mills' => [Role::PENGURUSAN, true, false],
            'manager own mill' => [Role::PENGURUS_KILANG, false, false],
            'admin all mills' => [Role::ADMIN, true, true],
        ];
    }

    #[DataProvider('viewerRoles')]
    public function test_role_scoped_navigation_preview_and_upload(string $role, bool $allMills, bool $canUpload): void
    {
        $viewer = $this->user($role, $allMills ? null : $this->bbj);
        $own = $this->batch($this->bbj);
        $other = $this->batch($this->khg);
        $this->actingAs($viewer);
        $index = $this->get(route('ffb-receive.index'))->assertOk()->assertSee('FFB Receive');
        $this->assertSame(2, substr_count($index->getContent(), 'href="'.route('ffb-receive.index').'"'));
        if ($canUpload) {
            $index->assertSee('Upload Excel &amp; Semak Penerimaan', false);
        } else {
            $index->assertDontSee('type="file"', false);
        }
        $this->get(route('ffb-receive.show', $own))->assertOk();
        $this->get(route('ffb-receive.show', $other))->assertStatus($allMills ? 200 : 403);
        $response = $this->post(route('ffb-receive.store'), $this->payload($this->workbook([[]]), [
            'mill_id' => $this->khg->id,
        ]));
        $response->assertStatus($canUpload ? 302 : 403);
        $this->assertSame($canUpload ? 3 : 2, FfbImportBatch::count());
    }

    public function test_guests_inactive_and_unassigned_officers_cannot_access_workflow(): void
    {
        $this->get(route('ffb-receive.index'))->assertRedirect(route('login'));
        $this->officer->update(['is_active' => false]);
        $this->actingAs($this->officer)->get(route('ffb-receive.index'))->assertRedirect(route('login'));
        $unassigned = $this->user(Role::PEGAWAI_KILANG);
        $this->actingAs($unassigned)->get(route('ffb-receive.index'))->assertForbidden();
        $this->post(route('ffb-receive.store'), $this->payload($this->workbook([[]])))->assertForbidden();
    }

    public function test_current_confirmed_and_daily_differences_are_read_only(): void
    {
        $existing = $this->batch($this->bbj);
        $this->actingAs($this->officer)->post(route('ffb-receive.store'), $this->payload($this->workbook([
            ['weight' => 12],
        ])))->assertRedirect();
        $candidate = FfbImportBatch::latest('id')->first();
        $existing->update([
            'status' => 'confirmed', 'ticket_count' => 2, 'confirmed_daily_total_mt' => '10.00',
            'confirmed_by' => $this->officer->id, 'confirmed_at' => now(),
            'confirmation_snapshot' => ['attestation' => true],
        ]);
        DB::table('daily_operations')->insert([
            'mill_id' => $this->bbj->id, 'tarikh' => '2026-10-28', 'shift' => 'Harian',
            'bts_diterima' => '9.00', 'oer' => '19.99', 'ker' => '4.99',
            'ffb_import_batch_id' => $existing->id,
        ]);
        $before = DB::table('daily_operations')->first();
        $this->actingAs($this->officer)->post(route('ffb-receive.store'), $this->payload($this->workbook([
            ['weight' => 12],
        ])))->assertSessionHasErrors('receipt_date');
        $this->assertSame(2, FfbImportBatch::count());
        $this->get(route('ffb-receive.show', $candidate))->assertOk()
            ->assertSee('Batch disahkan')->assertSee('2.00 MT')->assertSee('3.00 MT');
        $this->assertEquals($before, DB::table('daily_operations')->first());
        $this->assertSame('confirmed', $existing->fresh()->status);
        $this->assertNull($candidate->supersedes_batch_id);
        $this->assertNull($candidate->confirmed_at);
    }

    public function test_uploaded_and_failed_pages_and_ticket_pagination_are_accessible(): void
    {
        $batch = $this->batch($this->bbj);
        $this->actingAs($this->officer)->get(route('ffb-receive.show', $batch))->assertOk()
            ->assertSee('Batch Dimuat Naik');
        $rows = [];
        for ($index = 0; $index < 51; $index++) {
            $rows[] = ['number' => 'PAGE-'.$index];
        }
        $this->post(route('ffb-receive.store'), $this->payload($this->workbook($rows)))->assertRedirect();
        $batch = FfbImportBatch::latest('id')->first();
        $this->get(route('ffb-receive.show', $batch))->assertOk()->assertSee('PAGE-49')->assertDontSee('PAGE-50');
        $this->get(route('ffb-receive.show', $batch).'?page=2')->assertOk()->assertSee('PAGE-50')->assertDontSee('PAGE-49');
    }

    public function test_identical_reuploads_are_new_candidates_not_overwrites(): void
    {
        $this->actingAs($this->officer);
        $file = $this->workbook([['number' => 'SAME']]);
        $this->post(route('ffb-receive.store'), $this->payload($file))->assertRedirect();
        $this->post(route('ffb-receive.store'), $this->payload($file))->assertRedirect();
        $this->assertSame(2, FfbImportBatch::count());
        $this->assertSame(2, FfbWeighbridgeTicket::count());
        $second = FfbImportBatch::latest('id')->first();
        $this->get(route('ffb-receive.show', $second))->assertOk()->assertSee('Fail yang sama pernah dimuat naik');
    }

    public function test_kbb_preview_formats_46_tickets_without_changing_persistence_or_exposing_unrelated_ppks(): void
    {
        foreach (['ppnj_owned' => 'Owned parent hidden', 'external_supplier' => 'External parent hidden'] as $category => $name) {
            $ppk = Ppk::create(['code' => $category, 'name' => $name]);
            WeighbridgeCentre::where('source_category', $category)->first()->update(['ppk_id' => $ppk->id]);
        }
        $rows = [];
        for ($index = 0; $index < 46; $index++) {
            $rows[] = [
                'heading' => 'GROUP '.($index % 3), 'number' => 'KBB-'.$index,
                'weight' => $index === 45 ? '35.36' : '8.00',
            ];
        }
        $this->actingAs($this->officer)->post(route('ffb-receive.store'), $this->payload($this->workbook($rows)))
            ->assertSessionHasNoErrors()->assertRedirect();
        $batch = FfbImportBatch::sole();
        $before = [
            'batch' => $batch->getRawOriginal(),
            'tickets' => DB::table('ffb_weighbridge_tickets')->orderBy('id')->get()->toJson(),
            'audit' => DB::table('audit_logs')->orderBy('id')->get()->toJson(),
            'sources' => DB::table('weighbridge_centres')->orderBy('id')->get()->toJson(),
        ];
        $this->assertSame(46, $batch->ticket_count);
        $this->assertSame('395.360000', $batch->parsed_total_mt);
        $this->assertSame(46, $batch->tickets()->count());
        $response = $this->get(route('ffb-receive.show', $batch))->assertOk()
            ->assertSee('KBB')->assertSee('395.36 MT')->assertSee('Jumlah Tiket')
            ->assertSee('Tiket Perlu Semakan')->assertSee('Butiran Tiket')
            ->assertSee('Ladang Milik PPNJ')->assertSee('BTS Bersepadu PPNJ–PPK')
            ->assertSee('Pembekal Luar')->assertSee('PPK Original')
            ->assertDontSee('Owned parent hidden')->assertDontSee('External parent hidden')
            ->assertDontSee('Calon Tiket')->assertDontSee('Semakan / Bukti Asal')
            ->assertDontSee('395.360000 MT')->assertSee('Sedia untuk Semakan Pegawai');
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//tbody/tr/td/details[@open]')->length);
        $this->assertSame(2, $xpath->query('//nav/details[@open]')->length);
        $this->assertSame(46, $xpath->query('//tbody/tr/td/details')->length);
        foreach ($xpath->query('//tbody/tr') as $row) {
            $cells = $xpath->query('./td', $row);
            if ($cells->length !== 8) {
                continue;
            }
            $integrated = str_contains($cells->item(5)->textContent, 'BTS Bersepadu PPNJ–PPK');
            $this->assertSame($integrated, str_contains($cells->item(4)->textContent, 'PPK:'));
        }
        // Technical category codes and original precision belong only in collapsed diagnostics.
        foreach (iterator_to_array($xpath->query('//details')) as $details) {
            $details->parentNode->removeChild($details);
        }
        $this->assertStringNotContainsString('ppnj_owned', $document->textContent);
        $this->assertStringNotContainsString('ppnj_ppk_integrated', $document->textContent);
        $this->assertStringNotContainsString('external_supplier', $document->textContent);
        $this->get(route('ffb-receive.index'))->assertOk()
            ->assertSee('46 tiket / 395.36 MT')->assertSee('Sedia untuk Semakan Pegawai');
        $this->assertSame($before['batch'], $batch->fresh()->getRawOriginal());
        $this->assertSame($before['tickets'], DB::table('ffb_weighbridge_tickets')->orderBy('id')->get()->toJson());
        $this->assertSame($before['audit'], DB::table('audit_logs')->orderBy('id')->get()->toJson());
        $this->assertSame($before['sources'], DB::table('weighbridge_centres')->orderBy('id')->get()->toJson());
        $owned = $batch->tickets()->where('source_category', 'ppnj_owned')->first();
        $this->assertSame('Owned parent hidden', $owned->resolution_snapshot['ppk']['name']);
        $this->assertNotNull($owned->ppk_id);
    }

    public function test_inactive_mills_future_dates_and_unknown_units_are_rejected(): void
    {
        $this->actingAs($this->officer);
        foreach ([
            ['receipt_date' => now()->addDay()->toDateString()],
            ['confirmed_weight_unit' => 'TON'],
            ['receipt_date' => '0001-01-01'],
        ] as $invalid) {
            $field = array_key_first($invalid);
            $this->post(route('ffb-receive.store'), $this->payload($this->workbook([[]]), $invalid))
                ->assertSessionHasErrors($field);
        }
        $this->bbj->update(['is_active' => false]);
        $this->officer->unsetRelation('mill');
        $this->post(route('ffb-receive.store'), $this->payload($this->workbook([[]])))->assertForbidden();
        $this->assertSame(0, FfbImportBatch::count());
    }

    public function test_officer_explicitly_confirms_46_ticket_kbb_receipt_without_stage_two_writes(): void
    {
        $rows = [];
        for ($index = 0; $index < 46; $index++) {
            $rows[] = [
                'heading' => 'GROUP '.($index % 3), 'number' => 'CONFIRM-'.$index,
                'weight' => $index === 45 ? '35.36' : '8.00',
            ];
        }
        $this->actingAs($this->officer)->post(route('ffb-receive.store'), $this->payload($this->workbook($rows), [
            'control_ticket_count' => 46, 'control_total_mt' => '395.36',
        ]))->assertRedirect();
        $batch = FfbImportBatch::sole();
        $daily = DB::table('daily_operations')->get()->toJson();
        $tickets = DB::table('ffb_weighbridge_tickets')->orderBy('id')->get()->toJson();
        $original = $batch->getRawOriginal();
        $file = Storage::disk('ffb_receive')->get($batch->storage_path);
        $this->get(route('ffb-receive.show', $batch))->assertOk()->assertSee('Sahkan Penerimaan BTS');
        $this->get(route('ffb-receive.confirmation', $batch))->assertOk()
            ->assertSee('KBB')->assertSee('28/10/2026')->assertSee('395.36 MT')->assertSee('46')
            ->assertSee('Ladang Milik PPNJ')->assertSee('BTS Bersepadu PPNJ–PPK')
            ->assertSee('Pembekal Luar')->assertSee('Amaran untuk Semakan Pegawai');
        $this->assertSame('ready', $batch->fresh()->status);
        $token = $this->app->make(FfbBatchConfirmationService::class)->summary($batch)['token'];
        $this->post(route('ffb-receive.confirm', $batch), [
            'acknowledgement' => 1, 'confirmation_token' => $token,
            'confirmed_by' => 999, 'confirmed_daily_total_mt' => '1.00',
        ])->assertSessionHasNoErrors()->assertRedirect(route('ffb-receive.show', $batch));
        $batch->refresh();
        $this->assertSame('confirmed', $batch->status);
        $this->assertSame($this->officer->id, $batch->confirmed_by);
        $this->assertNotNull($batch->confirmed_at);
        $this->assertSame('395.36', $batch->confirmed_daily_total_mt);
        $this->assertSame('395.360000', $batch->parsed_total_mt);
        $this->assertSame(46, $batch->ticket_count);
        $this->assertSame(1, $batch->active_slot);
        $this->assertNull($batch->daily_operation_id);
        $this->assertNull($batch->synced_at);
        foreach ($original as $key => $value) {
            if (! in_array($key, [
                'status', 'confirmed_by', 'confirmed_at', 'confirmed_daily_total_mt',
                'confirmation_snapshot', 'updated_at', 'active_slot',
            ], true)) {
                $this->assertSame($value, $batch->getRawOriginal($key), $key);
            }
        }
        $this->assertSame($tickets, DB::table('ffb_weighbridge_tickets')->orderBy('id')->get()->toJson());
        $this->assertSame($daily, DB::table('daily_operations')->get()->toJson());
        $this->assertSame($file, Storage::disk('ffb_receive')->get($batch->storage_path));
        $audit = AuditLog::where('action', 'ffb_confirmed')->sole();
        $this->assertSame($this->officer->id, $audit->user_id);
        $this->assertSame('FfbImportBatch', $audit->model_type);
        $this->assertSame($batch->id, $audit->model_id);
        $this->assertSame(['status' => 'ready'], $audit->old_values);
        $this->assertSame($this->bbj->id, $audit->new_values['mill']['id']);
        $this->assertSame('BBJ', $audit->new_values['mill']['code']);
        $this->assertSame('2026-10-28', $audit->new_values['receipt_date']);
        $this->assertSame($batch->id, $audit->new_values['batch_id']);
        $this->assertSame($this->officer->id, $audit->new_values['confirmed_by']);
        $this->assertSame(46, $audit->new_values['ticket_count']);
        $this->assertSame('395.360000', $audit->new_values['total_mt']);
        $this->assertNotNull($audit->ip_address);
        $this->assertSame($batch->confirmation_snapshot, array_diff_key($audit->new_values, ['status' => true]));
        $this->assertSame('ready', $original['status']);
        $this->assertSame(1, $batch->confirmation_snapshot['stage']);
        $this->assertSame(16, $batch->confirmation_snapshot['categories']['ppnj_owned']['ticket_count']);
        $this->assertSame(15, $batch->confirmation_snapshot['categories']['ppnj_ppk_integrated']['ticket_count']);
        $this->assertSame(15, $batch->confirmation_snapshot['categories']['external_supplier']['ticket_count']);
        $this->get(route('ffb-receive.show', $batch))->assertOk()->assertSee('Penerimaan BTS Disahkan')
            ->assertSee($this->officer->name)->assertDontSee('>Sahkan Penerimaan BTS</a>', false);
        $this->get(route('ffb-receive.index'))->assertOk()->assertSee('Disahkan')->assertSee('(MYT)');
        $this->post(route('ffb-receive.confirm', $batch), [
            'acknowledgement' => 1, 'confirmation_token' => $token,
        ])->assertSessionHasErrors('confirmation');
        $this->assertSame(1, AuditLog::where('action', 'ffb_confirmed')->count());
    }

    public static function confirmationRoles(): array
    {
        return [
            'same mill officer' => [Role::PEGAWAI_KILANG, true, true],
            'other mill officer' => [Role::PEGAWAI_KILANG, false, false],
            'admin all mills' => [Role::ADMIN, false, true],
            'manager read only' => [Role::PENGURUS_KILANG, true, false],
            'management read only' => [Role::PENGURUSAN, false, false],
        ];
    }

    #[DataProvider('confirmationRoles')]
    public function test_confirmation_permissions_are_enforced_on_get_and_post(string $role, bool $ownMill, bool $allowed): void
    {
        $batch = $this->readyConfirmationBatch();
        $user = $this->user($role, $ownMill ? $this->bbj : $this->khg);
        $token = $this->app->make(FfbBatchConfirmationService::class)->summary($batch)['token'];
        $this->actingAs($user)->get(route('ffb-receive.confirmation', $batch))->assertStatus($allowed ? 200 : 403);
        $response = $this->post(route('ffb-receive.confirm', $batch), [
            'acknowledgement' => 1, 'confirmation_token' => $token,
        ]);
        if ($allowed) {
            $response->assertRedirect()->assertSessionHasNoErrors();
            $this->assertNotEmpty($batch->fresh()->confirmation_snapshot['warnings_acknowledged']);
            $this->assertSame('3.580245', $batch->fresh()->confirmation_snapshot['total_mt']);
            $this->assertSame('3.58', $batch->fresh()->confirmed_daily_total_mt);
        } else {
            $response->assertForbidden();
        }
        $this->assertSame($allowed ? 'confirmed' : 'ready', $batch->fresh()->status);
        $this->assertSame($allowed ? 1 : 0, AuditLog::where('action', 'ffb_confirmed')->count());
    }

    public function test_confirmation_requires_attestation_and_a_fresh_summary(): void
    {
        $batch = $this->readyConfirmationBatch();
        $summary = $this->app->make(FfbBatchConfirmationService::class)->summary($batch);
        $this->post(route('ffb-receive.confirm', $batch), [
            'confirmation_token' => $summary['token'],
        ])->assertSessionHasErrors('acknowledgement');
        $this->post(route('ffb-receive.confirm', $batch), [
            'acknowledgement' => 'no', 'confirmation_token' => $summary['token'],
        ])->assertSessionHasErrors('acknowledgement');
        $ticket = $batch->tickets()->first();
        $snapshot = $ticket->resolution_snapshot;
        $snapshot['source']['name'] = 'Changed after summary';
        $ticket->update(['resolution_snapshot' => $snapshot]);
        $this->post(route('ffb-receive.confirm', $batch), [
            'acknowledgement' => 1, 'confirmation_token' => $summary['token'],
        ])->assertSessionHasErrors('confirmation');
        $this->assertSame('ready', $batch->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'ffb_confirmed')->count());
    }

    public static function invalidConfirmationBatches(): array
    {
        return array_map(fn ($case) => [$case], [
            'uploaded', 'needs_review', 'failed', 'cancelled', 'superseded',
            'no_tickets', 'review_flag', 'review_reason', 'invalid_weight', 'duplicate',
            'count_mismatch', 'total_mismatch', 'control_count', 'control_total',
            'validation_error', 'unresolved_count', 'missing_snapshot', 'incomplete_total',
            'ticket_date', 'missing_unit', 'bad_category', 'missing_parent', 'missing_preview',
            'failed_count_evidence', 'failed_mt_evidence',
        ]);
    }

    #[DataProvider('invalidConfirmationBatches')]
    public function test_invalid_batches_cannot_be_confirmed(string $case): void
    {
        $batch = $this->readyConfirmationBatch();
        $ticket = $batch->tickets()->first();
        match ($case) {
            'uploaded', 'needs_review', 'failed', 'cancelled', 'superseded' => $batch->update(['status' => $case]),
            'no_tickets' => $batch->tickets()->delete(),
            'review_flag' => $ticket->update(['requires_review' => true]),
            'review_reason' => $ticket->update(['review_reasons' => ['duplicate_ticket_number']]),
            'invalid_weight' => $ticket->update(['weight_mt' => null]),
            'duplicate' => $batch->tickets()->orderByDesc('id')->first()->update(['ticket_number' => $ticket->ticket_number]),
            'count_mismatch' => $batch->update(['ticket_count' => 99]),
            'total_mismatch' => $batch->update(['parsed_total_mt' => '99.00']),
            'control_count' => $batch->update(['control_ticket_count' => 99]),
            'control_total' => $batch->update(['control_total_mt' => '99.00']),
            'validation_error' => $batch->update(['validation_summary' => ['errors' => ['Unresolved validation']]]),
            'unresolved_count' => $batch->update(['unresolved_count' => 1]),
            'missing_snapshot' => $ticket->update(['resolution_snapshot' => null]),
            'incomplete_total' => $batch->update(['has_complete_weight_total' => false]),
            'ticket_date' => $ticket->update(['ticket_date' => '2026-10-27']),
            'missing_unit' => $ticket->update(['weight_unit' => null]),
            'bad_category' => $ticket->update(['source_category' => null]),
            'missing_parent' => $batch->tickets()->where('source_category', 'ppnj_ppk_integrated')->first()->update(['ppk_id' => null]),
            'missing_preview' => $batch->update(['preview_hash' => null]),
            'failed_count_evidence' => $batch->update(['reconciliation_evidence' => ['ticket_count_difference' => 1]]),
            'failed_mt_evidence' => $batch->update(['reconciliation_evidence' => ['total_mt_difference' => '-1.000000']]),
        };
        $batch->refresh();
        $summary = $this->app->make(FfbBatchConfirmationService::class)->summary($batch);
        $this->assertNotEmpty($summary['errors']);
        $this->get(route('ffb-receive.confirmation', $batch))->assertOk()->assertSee('Pengesahan Tidak Dibenarkan')
            ->assertDontSee('type="submit"', false);
        $this->post(route('ffb-receive.confirm', $batch), [
            'acknowledgement' => 1, 'confirmation_token' => $summary['token'],
        ])->assertSessionHasErrors('confirmation');
        $this->assertNull($batch->fresh()->confirmed_at);
        $this->assertSame(0, AuditLog::where('action', 'ffb_confirmed')->count());
    }

    public function test_competing_batch_cannot_replace_current_confirmation(): void
    {
        $first = $this->readyConfirmationBatch();
        $second = $this->readyConfirmationBatch();
        $service = $this->app->make(FfbBatchConfirmationService::class);
        $firstToken = $service->summary($first)['token'];
        $secondToken = $service->summary($second)['token'];
        $this->post(route('ffb-receive.confirm', $first), [
            'acknowledgement' => 1, 'confirmation_token' => $firstToken,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('ffb-receive.confirm', $second), [
            'acknowledgement' => 1, 'confirmation_token' => $secondToken,
        ])->assertSessionHasErrors('confirmation');
        $this->assertSame('ready', $second->fresh()->status);
        $this->assertSame('confirmed', $first->fresh()->status);
        $this->assertSame(1, FfbImportBatch::where('status', 'confirmed')->count());
        $this->assertSame(1, AuditLog::where('action', 'ffb_confirmed')->count());
    }

    public function test_confirmation_rolls_back_if_audit_cannot_be_written(): void
    {
        $batch = $this->readyConfirmationBatch();
        $token = $this->app->make(FfbBatchConfirmationService::class)->summary($batch)['token'];
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->action === 'ffb_confirmed') {
                throw new RuntimeException('Audit storage unavailable');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post(route('ffb-receive.confirm', $batch), [
                'acknowledgement' => 1, 'confirmation_token' => $token,
            ]);
            $this->fail('Audit failure should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable', $exception->getMessage());
            $this->assertSame('ready', $batch->fresh()->status);
            $this->assertNull($batch->fresh()->confirmed_by);
            $this->assertNull($batch->fresh()->confirmation_snapshot);
            $this->assertSame(0, AuditLog::where('action', 'ffb_confirmed')->count());
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_missing_original_file_and_inactive_mill_block_confirmation(): void
    {
        $batch = $this->readyConfirmationBatch();
        $token = $this->app->make(FfbBatchConfirmationService::class)->summary($batch)['token'];
        Storage::disk('ffb_receive')->delete($batch->storage_path);
        $this->post(route('ffb-receive.confirm', $batch), [
            'acknowledgement' => 1, 'confirmation_token' => $token,
        ])->assertSessionHasErrors('confirmation');
        $this->bbj->update(['is_active' => false]);
        $this->post(route('ffb-receive.confirm', $batch), [
            'acknowledgement' => 1, 'confirmation_token' => $token,
        ])->assertForbidden();
        $this->assertSame('ready', $batch->fresh()->status);
    }

    public function test_replaced_original_file_and_revoked_permission_block_confirmation(): void
    {
        $batch = $this->readyConfirmationBatch();
        $service = $this->app->make(FfbBatchConfirmationService::class);
        $token = $service->summary($batch)['token'];
        Storage::disk('ffb_receive')->put($batch->storage_path, 'Replaced workbook content');
        $this->post(route('ffb-receive.confirm', $batch), [
            'acknowledgement' => 1, 'confirmation_token' => $token,
        ])->assertSessionHasErrors('confirmation');
        $role = Role::firstOrCreate(['name' => Role::PENGURUS_KILANG], ['label' => 'Manager']);
        DB::table('users')->where('id', $this->officer->id)->update(['role_id' => $role->id]);
        // A stale authenticated model must not bypass the fresh authorization inside the transaction.
        try {
            $service->confirm($batch->id, $this->officer, $token);
            $this->fail('Revoked confirmation permission should be rejected.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            $this->assertSame('ready', $batch->fresh()->status);
            $this->assertSame(0, AuditLog::where('action', 'ffb_confirmed')->count());
        }
    }

    public function test_database_unique_slot_protects_against_two_current_confirmations(): void
    {
        $first = $this->readyConfirmationBatch();
        $second = $this->readyConfirmationBatch();
        $service = $this->app->make(FfbBatchConfirmationService::class);
        $this->post(route('ffb-receive.confirm', $first), [
            'acknowledgement' => 1, 'confirmation_token' => $service->summary($first)['token'],
        ])->assertSessionHasNoErrors();
        try {
            DB::table('ffb_import_batches')->where('id', $second->id)->update(['status' => 'confirmed']);
            $this->fail('The database must reject a second active confirmation.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            $this->assertSame('ready', $second->fresh()->status);
            $this->assertSame(1, FfbImportBatch::where('status', 'confirmed')->count());
        }
    }

    private function readyConfirmationBatch(?string $date = null): FfbImportBatch
    {
        $this->actingAs($this->officer)->post(route('ffb-receive.store'), $this->payload($this->workbook([
            ['number' => 'FIRST', 'weight' => '1.234567', 'date' => $date ?? '2026-10-28'],
            ['number' => 'SECOND', 'heading' => 'GROUP 1', 'weight' => '2.345678', 'date' => $date ?? '2026-10-28'],
        ]), $date ? ['receipt_date' => $date] : []))->assertSessionHasNoErrors()->assertRedirect();

        return FfbImportBatch::latest('id')->first();
    }

    private function dailyPayload(string $date, string $received): array
    {
        $processed = min(2.0, (float) $received);

        return [
            'mill_id' => $this->bbj->id, 'tarikh' => $date, 'operation_status' => 'Operasi',
            'bts_diterima' => $received, 'bts_diproses' => number_format($processed, 2, '.', ''), 'jam_operasi' => '8.00',
            'downtime_jam' => '1.00', 'pengeluaran_cpo' => '0.00', 'pengeluaran_pk' => '0.00',
            'pk_kcp_to_hopper' => '0.00', 'produksi_cpo' => '0.00', 'produksi_pk' => '0.00',
            'stok_cpo_yesterday' => '50.00', 'stok_pk_yesterday' => '20.00',
            'stok_cpo' => '50.00', 'stok_pk' => '20.00',
            'baki_bts_semalam' => '0.00',
            'baki_bts_selepas_diproses' => number_format(max(0, (float) $received - $processed), 2, '.', ''),
        ];
    }

    public function test_storage_failure_is_explicit_and_never_creates_a_successful_batch(): void
    {
        $filesystems = Storage::getFacadeRoot();
        $disk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->shouldReceive('putFileAs')->once()->andThrow(new RuntimeException('Private storage unavailable'));
        Storage::shouldReceive('disk')->with('ffb_receive')->andReturn($disk);
        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Private storage unavailable');
        try {
            $this->actingAs($this->officer)->post(route('ffb-receive.store'), $this->payload($this->workbook([[]])));
        } finally {
            $this->assertSame(0, FfbImportBatch::count());
            $this->assertSame(0, AuditLog::count());
            // Restore the disk for the normal test-file cleanup.
            Storage::swap($filesystems);
        }
    }

    private function payload(UploadedFile $file, array $overrides = []): array
    {
        return array_merge([
            'mill_id' => $this->bbj->id, 'receipt_date' => '2026-10-28',
            'workbook' => $file, 'confirmed_weight_unit' => 'MT',
        ], $overrides);
    }

    private function dashboardReceipt(Mill $mill, string $date, array $rows, string $status = 'confirmed', string $type = 'excel'): FfbImportBatch
    {
        $batch = FfbImportBatch::create([
            'mill_id' => $mill->id, 'receipt_date' => $date, 'uploaded_by' => $this->officer->id,
            'receipt_type' => $type, 'status' => $status, 'ticket_count' => count($rows),
            'parsed_total_mt' => array_sum(array_map(fn ($row) => (float) $row[1], $rows)),
            'has_complete_weight_total' => true, 'unresolved_count' => 0,
            'confirmed_by' => $status === 'confirmed' ? $this->officer->id : null,
            'confirmed_at' => $status === 'confirmed' ? now() : null,
        ]);
        foreach ($rows as $index => [$source, $weight]) {
            FfbWeighbridgeTicket::create([
                'ffb_import_batch_id' => $batch->id, 'worksheet' => 'Sheet1', 'row_number' => $index + 1,
                'ticket_number' => 'T'.$index, 'weight_mt' => $weight, 'raw_values' => ['alias' => 'ALIAS-'.$index],
                'source_category' => $source->source_category, 'weighbridge_centre_id' => $source->id,
                'ppk_id' => $source->ppk_id, 'requires_review' => false,
                'resolution_snapshot' => [
                    'source' => ['id' => $source->id, 'name' => $source->name],
                    'ppk' => $source->ppk ? ['id' => $source->ppk->id, 'name' => $source->ppk->name] : null,
                ],
            ]);
        }

        return $batch;
    }

    private function user(string $role, ?Mill $mill = null): User
    {
        $record = Role::firstOrCreate(['name' => $role], ['label' => $role]);

        return User::create([
            'name' => 'FFB '.$role, 'email' => uniqid('ffb-', true).'@test.local',
            'password' => 'test-password', 'role_id' => $record->id,
            'mill_id' => $mill?->id, 'is_active' => true,
        ]);
    }

    private function batch(Mill $mill): FfbImportBatch
    {
        return FfbImportBatch::create([
            'mill_id' => $mill->id, 'receipt_date' => '2026-10-28', 'uploaded_by' => $this->officer->id,
            'original_filename' => $mill->id === $this->khg->id ? 'OTHER-MILL.xlsx' : 'OWN-MILL.xlsx',
            'storage_disk' => 'ffb_receive', 'storage_path' => 'fixture.xlsx',
            'file_sha256' => str_repeat('a', 64), 'file_size_bytes' => 1,
        ]);
    }

    private function workbook(
        array $tickets,
        string $format = 'Xlsx',
        string $filename = 'tickets.xlsx',
        ?string $label = 'MT',
    ): UploadedFile {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        if ($label !== null) {
            $sheet->setCellValue('K1', 'Nett Weight '.$label);
        }
        $row = 3;
        foreach ($tickets as $index => $ticket) {
            $sheet->setCellValue('A'.$row, 'Name :');
            $sheet->setCellValue('C'.$row, $ticket['heading'] ?? 'GROUP 0');
            $row++;
            foreach ([
                'A' => $index + 1, 'B' => $ticket['code'] ?? 'BLOCK',
                'E' => $ticket['date'] ?? '2026-10-28', 'F' => $ticket['number'] ?? 'T'.$index,
                'H' => $ticket['do'] ?? '00123', 'I' => $ticket['vehicle'] ?? 'ABC 123',
                'K' => $ticket['weight'] ?? 1,
            ] as $column => $value) {
                $sheet->setCellValueExplicit($column.$row, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $row++;
        }
        $path = tempnam(sys_get_temp_dir(), 'ffb-upload-test-');
        $this->files[] = $path;
        IOFactory::createWriter($book, $format)->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, $filename, null, null, true);
    }

    private function createFixtureSchema(): void
    {
        // Explicit disposable fixtures only: never invoke migrations/seeders or use MySQL.
        foreach ([
            'CREATE TABLE system_settings (id INTEGER PRIMARY KEY, key TEXT, value TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, label TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE mills (id INTEGER PRIMARY KEY, code TEXT, name TEXT, location TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT, role_id INTEGER, mill_id INTEGER, is_active INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, model_type TEXT, model_id INTEGER, old_values TEXT, new_values TEXT, ip_address TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE daily_operations (
                id INTEGER PRIMARY KEY, tarikh TEXT, mill_id INTEGER, shift TEXT, operation_status TEXT,
                officer_id INTEGER, bts_diterima NUMERIC, bts_diproses NUMERIC, baki_bts_semalam NUMERIC,
                baki_bts_selepas_diproses NUMERIC, jam_operasi NUMERIC, downtime_jam NUMERIC, sebab_downtime TEXT,
                pengeluaran_cpo NUMERIC, pengeluaran_pk NUMERIC, pk_kcp_to_hopper NUMERIC,
                produksi_cpo NUMERIC, produksi_pk NUMERIC, stok_cpo NUMERIC, stok_pk NUMERIC,
                stok_cpo_yesterday NUMERIC, stok_pk_yesterday NUMERIC, oer NUMERIC, ker NUMERIC,
                ffa NUMERIC, moisture NUMERIC, dirt NUMERIC, throughput NUMERIC, utilisation_rate NUMERIC,
                isu_operasi TEXT, tindakan_pembetulan TEXT, catatan_tambahan TEXT, status TEXT,
                last_amendment_reason TEXT, last_amended_by INTEGER, last_amended_at TEXT,
                ffb_import_batch_id INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE ppks (id INTEGER PRIMARY KEY, code TEXT, name TEXT, entity_type TEXT DEFAULT "official_ppk", is_active INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE weighbridge_centres (id INTEGER PRIMARY KEY, code TEXT, name TEXT, source_category TEXT, ppk_id INTEGER, is_active INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE ffb_source_groups (id INTEGER PRIMARY KEY, heading TEXT, normalized_heading TEXT, source_category TEXT, weighbridge_centre_id INTEGER, is_active INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE ffb_source_code_mappings (id INTEGER PRIMARY KEY, ffb_source_group_id INTEGER, code TEXT, normalized_code TEXT, weighbridge_centre_id INTEGER, is_active INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE ffb_import_batches (
                id INTEGER PRIMARY KEY, mill_id INTEGER NOT NULL, receipt_date TEXT NOT NULL, uploaded_by INTEGER NOT NULL,
                receipt_type TEXT NOT NULL DEFAULT "excel",
                original_filename TEXT, storage_disk TEXT, storage_path TEXT, file_sha256 TEXT, file_size_bytes INTEGER,
                mime_type TEXT, detected_format TEXT, status TEXT DEFAULT "uploaded", parser_version TEXT, resolver_version TEXT,
                confirmed_weight_unit TEXT, ticket_count INTEGER, parsed_total_mt NUMERIC, has_complete_weight_total INTEGER DEFAULT 0,
                unresolved_count INTEGER, validation_summary TEXT, control_ticket_count INTEGER, control_total_mt NUMERIC,
                reconciliation_evidence TEXT, validated_at TEXT, preview_hash TEXT, confirmed_by INTEGER, confirmed_at TEXT,
                confirmation_reason TEXT, confirmation_snapshot TEXT, confirmed_daily_total_mt NUMERIC,
                supersedes_batch_id INTEGER, daily_operation_id INTEGER, synced_at TEXT, created_at TEXT, updated_at TEXT,
                active_slot INTEGER GENERATED ALWAYS AS (CASE WHEN status = "confirmed" THEN 1 ELSE NULL END) VIRTUAL,
                UNIQUE(mill_id, receipt_date, active_slot))',
            'CREATE TABLE ffb_weighbridge_tickets (
                id INTEGER PRIMARY KEY, ffb_import_batch_id INTEGER NOT NULL, worksheet TEXT, row_number INTEGER,
                heading_row_number INTEGER, original_heading TEXT, original_source_code TEXT,
                ticket_number TEXT, normalized_ticket_number TEXT, ticket_date TEXT, do_number TEXT, vehicle_number TEXT,
                raw_values TEXT NOT NULL, weight_unit TEXT, weight_unit_origin TEXT, weight_mt NUMERIC,
                ffb_source_group_id INTEGER, weighbridge_centre_id INTEGER, ppk_id INTEGER, source_category TEXT,
                resolution_snapshot TEXT, requires_review INTEGER DEFAULT 1, review_reasons TEXT,
                created_at TEXT, updated_at TEXT, UNIQUE(ffb_import_batch_id, worksheet, row_number))',
        ] as $sql) {
            DB::statement($sql);
        }
    }
}
