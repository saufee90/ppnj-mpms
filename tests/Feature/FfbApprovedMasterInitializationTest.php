<?php

namespace Tests\Feature;

use App\Models\FfbSourceCodeMapping;
use App\Models\FfbSourceGroup;
use App\Models\Ppk;
use App\Models\WeighbridgeCentre;
use App\Services\FfbApprovedMasterInitializer;
use App\Services\FfbExcelTicketParser;
use App\Services\FfbSourceResolver;
use Database\Seeders\PpkMasterSeeder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FfbApprovedMasterInitializationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = DB::connection();
        $details = $connection->selectOne('SELECT DATABASE() AS database_name, VERSION() AS version');
        $this->assertSame('mysql', $connection->getDriverName());
        $targets = ['stage3-test-app' => 'mpms_ffb_stage3_test', 'master-uat' => 'mpms_ffb_master_uat'];
        $this->assertArrayHasKey(app()->environment(), $targets);
        $this->assertSame($targets[app()->environment()], $details->database_name);
        $this->assertContains($connection->getConfig('host'), ['localhost', '127.0.0.1']);
        $this->assertStringStartsWith('8.4.3', $details->version);
    }

    public function test_approved_master_initialization_is_idempotent_and_preserves_operations(): void
    {
        $initializer = app(FfbApprovedMasterInitializer::class);
        $preview = $initializer->initialize(true);
        $first = $initializer->initialize();
        $second = $initializer->initialize();

        $expected = [
            'ppks' => 30,
            'weighbridge_centres' => 31,
            'ffb_source_aliases' => 0,
            'ffb_source_groups' => 17,
            'ffb_source_code_mappings' => 24,
        ];
        $this->assertSame($expected, $preview['master_counts_after']);
        $this->assertSame($preview['master_counts_before'], $first['master_counts_before']);
        $this->assertSame($preview['plan'], $first['plan']);
        $this->assertSame($preview['operational_before'], $first['operational_before']);
        $this->assertSame($expected, $first['master_counts_after']);
        $this->assertSame($expected, $second['master_counts_before']);
        $this->assertSame($expected, $second['master_counts_after']);
        $this->assertSame($second['operational_before'], $second['operational_after']);
        $this->assertSame($first['operational_after'], $second['operational_after']);
        $this->assertSame(29, Ppk::official()->count());
        $this->assertSame(1, Ppk::where('entity_type', Ppk::INDEPENDENT_INTEGRATED_ESTATE)->count());
        $this->assertSame(1, WeighbridgeCentre::where('code', 'SRC-LADANG-KAHANG-1')->count());
        $this->assertSame(
            'PPK-SIMPANG-LIMA',
            PpkMasterSeeder::canonicalCodeForName('PPK Simpang Lima Batu Pahat'),
        );
        $this->assertSame('PPK-SIMPANG-LIMA', PpkMasterSeeder::canonicalCodeForName('PPK SIMPANG LIMA'));
        $this->assertNull(PpkMasterSeeder::canonicalCodeForName('Unapproved PPK Alias'));
    }

    public function test_conflicting_existing_master_is_rejected_without_overwrite(): void
    {
        $record = Ppk::where('code', 'PPK-SIMPANG-LIMA')->firstOrFail();
        $originalName = $record->name;
        DB::beginTransaction();

        try {
            $record->update(['name' => 'Conflicting PPK name']);
            try {
                app(FfbApprovedMasterInitializer::class)->initialize();
                $this->fail('A conflicting PPK record must abort initialization.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('PPK-SIMPANG-LIMA', $exception->getMessage());
            }
            $this->assertSame('Conflicting PPK name', $record->fresh()->name);
        } finally {
            DB::rollBack();
        }

        $this->assertSame($originalName, $record->fresh()->name);
    }

    public function test_dry_run_rejects_unexpected_master_without_changing_existing_data(): void
    {
        DB::beginTransaction();
        try {
            $extra = WeighbridgeCentre::create([
                'code' => 'UNEXPECTED-MASTER-TEST', 'name' => 'Unexpected test master',
                'source_category' => 'ppnj_owned',
            ]);
            try {
                app(FfbApprovedMasterInitializer::class)->initialize(true);
                $this->fail('Dry-run must reject unexpected masters.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('UNEXPECTED-MASTER-TEST', $exception->getMessage());
            }
            $this->assertTrue($extra->fresh()->exists);
        } finally {
            DB::rollBack();
        }
        $this->assertFalse(WeighbridgeCentre::where('code', 'UNEXPECTED-MASTER-TEST')->exists());
    }

    public function test_supplied_workbooks_match_approved_sources_and_official_ppk_totals(): void
    {
        $parser = new FfbExcelTicketParser(new FfbSourceResolver(
            new FfbSourceGroup(),
            new FfbSourceCodeMapping(),
        ));
        $kbb = $parser->parse(base_path('test-samples/bts 28.10.2026 ksbb.xls'), 'KG', 'BBJ');
        $kahang = $parser->parse(base_path('test-samples/Kilang Kahang 281026.xls'), 'KG', 'KHG');

        $this->assertSame(46, $kbb->ticketCount);
        $this->assertSame(0.39536, $kbb->totalMt);
        $this->assertFalse($kbb->requiresReview);
        $this->assertSame(35, $kahang->ticketCount);
        $this->assertSame(0.72072, $kahang->totalMt);
        $this->assertFalse($kahang->requiresReview);

        $this->assertSame(
            ['external_supplier' => 7, 'ppnj_owned' => 27, 'ppnj_ppk_integrated' => 12],
            $this->categoryCounts($kbb->tickets),
        );
        $this->assertSame(
            ['external_supplier' => 18, 'ppnj_owned' => 4, 'ppnj_ppk_integrated' => 13],
            $this->categoryCounts($kahang->tickets),
        );

        $this->assertTopOfficialPpks($kbb->tickets, [
            ['PPK-SIMPANG-LIMA', 0.06548],
            ['PPK-SRI-MEDAN', 0.03414],
            ['PPK-BATU-ANAM', 0.01660],
            ['PPK-SEGAMAT', 0.00570],
            ['PPK-LEDANG-TANGKAK', 0.00378],
        ]);
        $this->assertTopOfficialPpks($kahang->tickets, [
            ['PPK-MUAR-SELATAN', 0.07080],
            ['PPK-RENGIT', 0.06622],
            ['PPK-PARIT-RAJA', 0.06266],
            ['PPK-SIMPANG-LIMA', 0.03436],
            ['PPK-SRI-MEDAN', 0.03180],
        ]);

        $blocks = array_filter($kbb->tickets, fn (array $ticket): bool =>
            $ticket['original_heading'] === 'LADANG BUKIT BUJANG'
        );
        $this->assertCount(1, array_unique(array_map(
            fn (array $ticket): int => $ticket['source_resolution']->source->id,
            $blocks,
        )));

        $pkayisUnknown = app(FfbSourceResolver::class)
            ->resolve('LADANG USAHASAMA PKAYIS&KDSSB', '99/B');
        $this->assertTrue($pkayisUnknown->requiresReview);
        $this->assertSame('unknown_contextual_code', $pkayisUnknown->reviewReason);
        $wrongMill = app(FfbSourceResolver::class)->resolveForMill('BBJ', 'LADANG KAHANG 2', 'PH 2B');
        $this->assertTrue($wrongMill->requiresReview);
        $this->assertSame('heading_not_assigned_to_mill', $wrongMill->reviewReason);
        $unknown = app(FfbSourceResolver::class)->resolveForMill('KHG', 'UNKNOWN SOURCE', 'X');
        $this->assertTrue($unknown->requiresReview);
        $this->assertSame('unknown_heading', $unknown->reviewReason);
    }

    private function categoryCounts(array $tickets): array
    {
        $counts = [];
        foreach ($tickets as $ticket) {
            $category = $ticket['source_resolution']->sourceCategory;
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    private function assertTopOfficialPpks(array $tickets, array $expected): void
    {
        $totals = [];
        foreach ($tickets as $ticket) {
            $resolution = $ticket['source_resolution'];
            if ($resolution->sourceCategory !== 'ppnj_ppk_integrated'
                || $resolution->ppk?->entity_type !== Ppk::OFFICIAL_PPK) {
                continue;
            }
            $code = $resolution->ppk->code;
            $totals[$code] = ($totals[$code] ?? 0.0) + $ticket['weight_mt'];
        }
        arsort($totals);
        $actual = array_slice(array_map(
            fn (string $code, float $weight): array => [$code, round($weight, 5)],
            array_keys($totals),
            array_values($totals),
        ), 0, 5);

        $this->assertSame($expected, $actual);
        $this->assertArrayNotHasKey(Ppk::KLUANG_UTARA_CODE, $totals);
    }
}
