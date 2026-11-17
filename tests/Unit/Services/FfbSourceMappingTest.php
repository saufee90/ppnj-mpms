<?php

namespace Tests\Unit\Services;

use App\Models\FfbSourceAlias;
use App\Models\FfbSourceCodeMapping;
use App\Models\FfbSourceGroup;
use App\Models\Ppk;
use App\Models\WeighbridgeCentre;
use App\Services\FfbSourceResolver;
use App\Services\FfbExcelTicketParser;
use Database\Seeders\FfbSourceMappingSeeder;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use LogicException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

class FfbSourceMappingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const HEADING = 'PERTUBUHAN PELADANG NEGERI JOHOR';

    private ?ContainerContract $previousApplication;

    private ?ConnectionResolverInterface $previousResolver;

    private array $records = [];

    private array $models = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication(new Container());
        $connection = new MySqlConnection(function () {
            throw new LogicException('Database access is forbidden in this unit test.');
        });
        $resolver = new ConnectionResolver(['unit' => $connection]);
        $resolver->setDefaultConnection('unit');
        Model::setConnectionResolver($resolver);

        // All seeder/resolver persistence is mocked in memory, including transactions.
        $database = Mockery::mock(DatabaseManager::class);
        DB::swap($database);
        $database->shouldReceive('transaction')->andReturnUsing(function (callable $callback) {
            $before = $this->records;

            try {
                return $callback();
            } catch (Throwable $exception) {
                $this->records = $before;
                throw $exception;
            }
        });

        foreach ([Ppk::class, WeighbridgeCentre::class, FfbSourceGroup::class, FfbSourceCodeMapping::class] as $class) {
            $this->records[$class] = [];
            $model = Mockery::mock($class);
            $model->shouldReceive('newQuery')->andReturnUsing(fn () => $this->query($class));
            $this->models[$class] = $model;
        }

        foreach ([
            'PPK-PARIT-RAJA', 'PPK-SIMPANG-LIMA', 'PPK-RENGIT', 'PPK-SRI-MEDAN',
            'PPK-BATU-ANAM', 'PPK-MUAR-SELATAN', 'PPK-PONTIAN-SELATAN', 'PPK-LEDANG-TANGKAK',
            'PPK-SEGAMAT', 'PPK-KLUANG',
        ] as $code) {
            $this->insert(Ppk::class, ['code' => $code, 'name' => $code]);
        }
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication($this->previousApplication);

        if ($this->previousResolver === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($this->previousResolver);
        }

        parent::tearDown();
    }

    public function test_confirmed_ppk_codes_resolve_to_distinct_sources_under_existing_ppks(): void
    {
        $this->seedMockStore();
        $expected = [
            'PT RAJA 1' => 'PPK-PARIT-RAJA',
            'PT RAJA 2' => 'PPK-PARIT-RAJA',
            'PT RAJA 3' => 'PPK-PARIT-RAJA',
            'B/UTARA 2' => 'PPK-SIMPANG-LIMA',
            'B.UTARA 3' => 'PPK-SIMPANG-LIMA',
            'RENGIT/1' => 'PPK-RENGIT',
            'RENGIT/2' => 'PPK-RENGIT',
            'S/MEDAN' => 'PPK-SRI-MEDAN',
            'B/ANAM' => 'PPK-BATU-ANAM',
            'B/ANAM 1' => 'PPK-BATU-ANAM',
            'MUAR SEL 1' => 'PPK-MUAR-SELATAN',
            'PON SELATAN' => 'PPK-PONTIAN-SELATAN',
            'LEDANG 2' => 'PPK-LEDANG-TANGKAK',
            'S/TENGAH' => 'PPK-SEGAMAT',
            'SIMPANG 5 /1' => 'PPK-SIMPANG-LIMA',
            'PPK KLUANG' => 'PPK-KLUANG',
        ];
        $sourceIds = [];

        foreach ($expected as $code => $ppk) {
            $result = $this->resolver()->resolve(self::HEADING, $code);

            $this->assertFalse($result->requiresReview);
            $this->assertSame('ppnj_ppk_integrated', $result->sourceCategory);
            $this->assertSame($ppk, $result->ppk->code);
            $this->assertSame($code, $result->source->name);
            $sourceIds[] = $result->source->id;
        }

        $this->assertCount(16, array_unique($sourceIds));
        $this->assertCount(11, $this->records[Ppk::class]);
    }

    public function test_owned_estates_keep_block_codes_as_attributes_not_global_aliases(): void
    {
        $this->seedMockStore();

        foreach ([
            'LADANG BUKIT BUJANG' => ['Ladang Bukit Bujang', ['013A', '018A', '04A']],
            'LADANG KAHANG 2' => ['Ladang Kahang 2', ['PH 1D', 'PH 2B']],
        ] as $heading => [$name, $codes]) {
            $ids = [];

            foreach ($codes as $block) {
                $result = $this->resolver()->resolve($heading, $block);

                $this->assertFalse($result->requiresReview);
                $this->assertSame('ppnj_owned', $result->sourceCategory);
                $this->assertSame($name, $result->source->name);
                $this->assertNull($result->ppk);
                $this->assertSame($block, $result->originalCode);
                $ids[] = $result->source->id;
            }

            $this->assertCount(1, array_unique($ids));
        }

        $consortiumIds = [];
        foreach (['23A', '23B'] as $block) {
            $result = $this->resolver()->resolve('LADANG KONSORTIUM PPNJ/PPK', $block);
            $this->assertFalse($result->requiresReview);
            $this->assertSame('ppnj_owned', $result->sourceCategory);
            $this->assertSame('SRC-LADANG-KONSORTIUM-PPNJ-PPK', $result->source->code);
            $this->assertNull($result->ppk);
            $consortiumIds[] = $result->source->id;
        }
        $this->assertCount(1, array_unique($consortiumIds));
        $this->assertCount(24, $this->records[FfbSourceCodeMapping::class]);
    }

    public function test_kahang_estates_use_the_approved_category_and_independent_grouping(): void
    {
        $this->seedMockStore();
        $owned = $this->resolver()->resolve('LADANG USAHASAMA PPNJ / JELAPANG MEGAH', 'B JELAPANG');
        $this->assertFalse($owned->requiresReview);
        $this->assertSame('ppnj_owned', $owned->sourceCategory);
        $this->assertSame('Jelapang Megah', $owned->source->name);
        $this->assertNull($owned->ppk);

        $integrated = $this->resolver()->resolve('LADANG USAHASAMA PPNJ / PPK KLUANG UTARA', '01 B');
        $this->assertFalse($integrated->requiresReview);
        $this->assertSame('ppnj_ppk_integrated', $integrated->sourceCategory);
        $this->assertSame(Ppk::KLUANG_UTARA_NAME, $integrated->ppk->name);
        $this->assertSame(Ppk::INDEPENDENT_INTEGRATED_ESTATE, $integrated->ppk->entity_type);
        $this->assertNotSame($this->find(Ppk::class, ['code' => 'PPK-KLUANG'])->id, $integrated->ppk->id);
        $this->assertSame($integrated->ppk->id, $integrated->source->ppk_id);
    }

    public function test_pkayis_activates_only_the_actual_workbook_code(): void
    {
        $this->seedMockStore();
        $verified = $this->resolver()->resolve('LADANG USAHASAMA PKAYIS&KDSSB', '99/A');
        $unverified = $this->resolver()->resolve('LADANG USAHASAMA PKAYIS&KDSSB', '99/B');

        $this->assertFalse($verified->requiresReview);
        $this->assertSame('external_supplier', $verified->sourceCategory);
        $this->assertSame('SRC-USAHASAMA-PKAYIS-KDSSB', $verified->source->code);
        $this->assertNull($verified->ppk);
        $this->assertTrue($unverified->requiresReview);
        $this->assertSame('unknown_contextual_code', $unverified->reviewReason);
    }

    public function test_known_workbook_headings_cannot_resolve_against_the_other_mill(): void
    {
        $this->seedMockStore();
        $wrongKbb = $this->resolver()->resolveForMill('BBJ', 'LADANG KAHANG 2', 'PH 2B');
        $wrongKahang = $this->resolver()->resolveForMill('KHG', 'LADANG BUKIT BUJANG', '09A');
        $sharedPpk = $this->resolver()->resolveForMill('KHG', self::HEADING, 'PT RAJA 1');

        $this->assertTrue($wrongKbb->requiresReview);
        $this->assertSame('heading_not_assigned_to_mill', $wrongKbb->reviewReason);
        $this->assertTrue($wrongKahang->requiresReview);
        $this->assertSame('heading_not_assigned_to_mill', $wrongKahang->reviewReason);
        $this->assertFalse($sharedPpk->requiresReview);
        $this->assertSame('PPK-PARIT-RAJA', $sharedPpk->ppk->code);
    }

    public function test_integrated_source_without_a_valid_grouping_requires_review(): void
    {
        $this->seedMockStore();
        $source = $this->find(WeighbridgeCentre::class, ['code' => 'SRC-USAHASAMA-PPK-KLUANG-UTARA']);
        $source->ppk_id = null;
        $result = $this->resolver()->resolve('LADANG USAHASAMA PPNJ / PPK KLUANG UTARA', '01 B');
        $this->assertTrue($result->requiresReview);
        $this->assertSame('missing_or_inactive_ppk', $result->reviewReason);
    }

    public function test_external_supplier_classification_is_explicit_not_name_based(): void
    {
        $this->seedMockStore();

        foreach ([
            'BANGAS AGRO ENTERPRISE',
            'KOPERASI PENANAM SAWIT MAMPAN SEGAMAT BERHAD',
            'MEWAH MEGA OIL PALM SDN BHD',
            'BIS JAYA TRADING SDN BHD',
            'GUAN LENG TRADING SDN BHD',
            'HJ MANSOR FAMILY AND SONS SDN BHD',
            'KAHANG OIL PALM TRADING',
        ] as $heading) {
            $result = $this->resolver()->resolve($heading, '013A');

            $this->assertFalse($result->requiresReview);
            $this->assertSame('external_supplier', $result->sourceCategory);
            $this->assertNull($result->ppk);
        }
    }

    public function test_matching_normalizes_case_and_surrounding_whitespace_but_preserves_raw_values(): void
    {
        $this->seedMockStore();
        $heading = " \tpertubuhan peladang negeri johor\r\n";
        $code = ' b/anam 1 ';
        $result = $this->resolver()->resolve($heading, $code);

        $this->assertFalse($result->requiresReview);
        $this->assertSame($heading, $result->originalHeading);
        $this->assertSame($code, $result->originalCode);
        $this->assertSame('B/ANAM 1', $result->source->name);
        $this->assertSame(self::HEADING, $result->group->heading);
    }

    public static function unknownCombinations(): array
    {
        return [
            'unknown heading with known code' => ['NOT REGISTERED', 'PT RAJA 1', 'unknown_heading'],
            'unknown code in integrated group' => [self::HEADING, '013A', 'unknown_contextual_code'],
            'punctuation is not guessed' => [self::HEADING, 'B/UTARA 3', 'unknown_contextual_code'],
            'internal whitespace is significant' => [self::HEADING, 'PT  RAJA 1', 'unknown_contextual_code'],
            'alternate heading requires confirmed code' => ['BIS JAYA TRADING SDN BHD (A)', '013A', 'unknown_contextual_code'],
            'words do not infer a category' => ['NEW LADANG USAHASAMA PPNJ / PPK', '23B', 'unknown_heading'],
            'blank heading' => [' ', 'PT RAJA 1', 'blank_heading_or_code'],
            'blank code' => ['LADANG KAHANG 2', "\t", 'blank_heading_or_code'],
        ];
    }

    #[DataProvider('unknownCombinations')]
    public function test_unknown_combinations_require_review_without_global_fallback(
        string $heading,
        string $code,
        string $reason,
    ): void {
        $this->seedMockStore();
        $result = $this->resolver()->resolve($heading, $code);

        $this->assertTrue($result->requiresReview);
        $this->assertSame($reason, $result->reviewReason);
        $this->assertNull($result->source);
        $this->assertNull($result->sourceCategory);
        $this->assertSame($heading, $result->originalHeading);
        $this->assertSame($code, $result->originalCode);
    }

    public function test_same_code_can_resolve_differently_in_two_heading_contexts(): void
    {
        $this->seedMockStore();
        $source = $this->records[WeighbridgeCentre::class][0];
        $group = $this->insert(FfbSourceGroup::class, [
            'heading' => 'ANOTHER CONFIRMED GROUP',
            'source_category' => 'ppnj_ppk_integrated',
        ]);
        $this->insert(FfbSourceCodeMapping::class, [
            'ffb_source_group_id' => $group->id,
            'code' => 'B/ANAM',
            'weighbridge_centre_id' => $source->id,
        ]);

        $first = $this->resolver()->resolve(self::HEADING, 'B/ANAM');
        $second = $this->resolver()->resolve($group->heading, 'B/ANAM');

        $this->assertFalse($first->requiresReview);
        $this->assertFalse($second->requiresReview);
        $this->assertNotSame($first->source->id, $second->source->id);
    }

    public function test_duplicate_heading_or_code_candidates_are_never_resolved_by_first_match(): void
    {
        $this->seedMockStore();
        $group = $this->records[FfbSourceGroup::class][0];
        $mapping = $this->records[FfbSourceCodeMapping::class][0];
        $this->records[FfbSourceCodeMapping::class][] = clone $mapping;
        $result = $this->resolver()->resolve(self::HEADING, $mapping->code);
        $this->assertSame('ambiguous_code', $result->reviewReason);
        $this->assertTrue($result->requiresReview);
        $this->assertNull($result->source);

        $this->records[FfbSourceGroup::class][] = clone $group;
        $result = $this->resolver()->resolve(self::HEADING, $mapping->code);
        $this->assertSame('ambiguous_heading', $result->reviewReason);
        $this->assertTrue($result->requiresReview);
    }

    public function test_conflicting_direct_heading_and_contextual_code_require_review(): void
    {
        $this->seedMockStore();
        $group = $this->find(FfbSourceGroup::class, ['heading' => 'LADANG KAHANG 2']);
        $this->insert(FfbSourceCodeMapping::class, [
            'ffb_source_group_id' => $group->id,
            'code' => '013A',
            'weighbridge_centre_id' => $this->records[WeighbridgeCentre::class][0]->id,
        ]);

        $result = $this->resolver()->resolve($group->heading, '013A');

        $this->assertTrue($result->requiresReview);
        $this->assertSame('conflicting_heading_and_code', $result->reviewReason);
        $this->assertNull($result->source);
    }

    public function test_category_mismatch_requires_review_even_for_an_exact_mapping(): void
    {
        $this->seedMockStore();
        $group = $this->find(FfbSourceGroup::class, ['heading' => 'LADANG KAHANG 2']);
        $group->source_category = 'external_supplier';
        $result = $this->resolver()->resolve($group->heading, '013A');

        $this->assertTrue($result->requiresReview);
        $this->assertSame('source_category_mismatch', $result->reviewReason);
        $this->assertNull($result->sourceCategory);
    }

    public function test_inactive_mappings_sources_and_ppks_require_review(): void
    {
        $this->seedMockStore();
        $group = $this->records[FfbSourceGroup::class][0];
        $mapping = $this->records[FfbSourceCodeMapping::class][0];
        $source = $this->find(WeighbridgeCentre::class, ['id' => $mapping->weighbridge_centre_id]);
        $ppk = $this->find(Ppk::class, ['id' => $source->ppk_id]);

        foreach ([
            [$group, 'inactive_heading'],
            [$mapping, 'inactive_code_mapping'],
            [$source, 'missing_or_inactive_source'],
            [$ppk, 'missing_or_inactive_ppk'],
        ] as [$model, $reason]) {
            $model->is_active = false;
            $result = $this->resolver()->resolve(self::HEADING, $mapping->code);
            $this->assertTrue($result->requiresReview);
            $this->assertSame($reason, $result->reviewReason);
            $model->is_active = true;
        }
    }

    public function test_seeding_twice_preserves_official_ppks_and_the_single_independent_parent(): void
    {
        $this->seedMockStore();
        $before = $this->snapshot();
        $this->seedMockStore();

        $this->assertSame($before, $this->snapshot());
        $this->assertCount(11, $this->records[Ppk::class]);
        $this->assertCount(31, $this->records[WeighbridgeCentre::class]);
        $this->assertCount(17, $this->records[FfbSourceGroup::class]);
        $this->assertCount(24, $this->records[FfbSourceCodeMapping::class]);
        $this->assertArrayNotHasKey(FfbSourceAlias::class, $this->models);
    }

    public function test_bis_jaya_alternate_heading_reuses_canonical_supplier_idempotently(): void
    {
        $this->seedMockStore();
        $before = $this->snapshot();
        $this->seedMockStore();

        $canonical = $this->resolver()->resolve('BIS JAYA TRADING SDN BHD', 'BIS JAYA TRD');
        $alternate = $this->resolver()->resolve('BIS JAYA TRADING SDN BHD (A)', 'BIS JAYA (A)');

        $this->assertFalse($canonical->requiresReview);
        $this->assertFalse($alternate->requiresReview);
        $this->assertNull($alternate->reviewReason);
        $this->assertSame($canonical->source->id, $alternate->source->id);
        $this->assertSame('SRC-BIS-JAYA', $alternate->source->code);
        $this->assertSame('BIS JAYA TRADING SDN BHD', $alternate->source->name);
        $this->assertSame('external_supplier', $alternate->sourceCategory);
        $this->assertNull($alternate->ppk);
        $this->assertNull($alternate->source->ppk_id);
        $this->assertSame('BIS JAYA TRADING SDN BHD (A)', $alternate->originalHeading);
        $this->assertSame('BIS JAYA (A)', $alternate->originalCode);
        $this->assertNull($alternate->group->weighbridge_centre_id);
        $this->assertNotSame($canonical->group->id, $alternate->group->id);
        $mapping = $this->find(FfbSourceCodeMapping::class, [
            'ffb_source_group_id' => $alternate->group->id,
            'code' => 'BIS JAYA (A)',
        ]);
        $this->assertSame($canonical->source->id, $mapping->weighbridge_centre_id);
        $this->assertSame($before, $this->snapshot());
        $this->assertCount(1, array_filter($this->records[WeighbridgeCentre::class],
            fn ($source) => $source->name === 'BIS JAYA TRADING SDN BHD'));
    }

    public function test_approved_heading_scoped_codes_reuse_their_canonical_sources(): void
    {
        $this->seedMockStore();

        foreach ([
            ['BANGAS AGRO ENTERPRISE', 'BAE', 'SRC-BANGAS-AGRO'],
            ['LADANG USAHASAMA PPNJ / JELAPANG MEGAH', 'B JELAPANG', 'SRC-USAHASAMA-JELAPANG-MEGAH'],
            ['LADANG USAHASAMA PPNJ / PPK KLUANG UTARA', '01 B', 'SRC-USAHASAMA-PPK-KLUANG-UTARA'],
        ] as [$heading, $code, $sourceCode]) {
            $result = $this->resolver()->resolve($heading, $code);

            $this->assertFalse($result->requiresReview);
            $this->assertSame($sourceCode, $result->source->code);
            $this->assertTrue($this->find(FfbSourceCodeMapping::class, ['code' => $code])->is_active);
        }
    }

    public static function confirmedAdditionalPpkSources(): array
    {
        return [
            'KBB Segamat' => ['S/TENGAH', 'SRC-S-TENGAH', 'PPK-SEGAMAT'],
            'KHG Simpang Lima' => ['SIMPANG 5 /1', 'SRC-SIMPANG-5-1', 'PPK-SIMPANG-LIMA'],
            'KHG Kluang' => ['PPK KLUANG', 'SRC-PPK-KLUANG', 'PPK-KLUANG'],
        ];
    }

    #[DataProvider('confirmedAdditionalPpkSources')]
    public function test_confirmed_additional_codes_reuse_existing_ppks_and_sources(
        string $code,
        string $sourceCode,
        string $ppkCode,
    ): void {
        $officialPpksBefore = $this->officialPpkSnapshot();
        $parent = $this->find(Ppk::class, ['code' => $ppkCode]);
        $existing = $this->insert(WeighbridgeCentre::class, [
            'code' => $sourceCode, 'name' => $code, 'ppk_id' => $parent->id,
            'source_category' => 'ppnj_ppk_integrated',
        ]);
        $this->seedMockStore();
        $before = $this->snapshot();
        $this->seedMockStore();
        $result = $this->resolver()->resolve(self::HEADING, $code);

        $this->assertFalse($result->requiresReview);
        $this->assertSame($existing->id, $result->source->id);
        $this->assertSame($parent->id, $result->ppk->id);
        $this->assertSame($ppkCode, $result->ppk->code);
        $this->assertSame('ppnj_ppk_integrated', $result->sourceCategory);
        $this->assertSame($code, $result->originalCode);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($officialPpksBefore, $this->officialPpkSnapshot());
        $this->assertCount(1, array_filter(
            $this->records[Ppk::class],
            fn (Ppk $ppk): bool => $ppk->entity_type === Ppk::INDEPENDENT_INTEGRATED_ESTATE,
        ));
        $this->assertCount(1, array_filter($this->records[WeighbridgeCentre::class],
            fn ($source) => $source->code === $sourceCode));
        $this->assertSame('unknown_heading', $this->resolver()->resolve('UNCONFIRMED HEADING', $code)->reviewReason);
    }

    #[DataProvider('confirmedAdditionalPpkSources')]
    public function test_additional_source_with_conflicting_ppk_is_not_reassigned(
        string $code,
        string $sourceCode,
        string $ppkCode,
    ): void {
        $wrongParent = $this->find(Ppk::class, ['code' => 'PPK-PARIT-RAJA']);
        $this->insert(WeighbridgeCentre::class, [
            'code' => $sourceCode, 'name' => $code, 'ppk_id' => $wrongParent->id,
            'source_category' => 'ppnj_ppk_integrated',
        ]);
        $before = $this->snapshot();

        try {
            $this->seedMockStore();
            $this->fail('Existing source must not be reassigned to '.$ppkCode);
        } catch (DomainException $exception) {
            $this->assertStringContainsString($sourceCode, $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_wakaf_mapping_reuses_existing_source_and_preserves_ppks_on_repeat_seeding(): void
    {
        $officialPpksBefore = $this->officialPpkSnapshot();
        $source = $this->insert(WeighbridgeCentre::class, [
            'code' => 'SRC-LADANG-WAKAF-JEMALUANG',
            'name' => 'LADANG WAKAF JEMALUANG',
            'ppk_id' => null,
            'source_category' => 'ppnj_owned',
        ]);
        $this->seedMockStore();
        $before = $this->snapshot();
        $this->seedMockStore();
        $result = $this->resolver()->resolve('LADANG WAKAF JEMALUANG', 'A+B+C');

        $this->assertFalse($result->requiresReview);
        $this->assertNull($result->reviewReason);
        $this->assertSame($source->id, $result->source->id);
        $this->assertSame('ppnj_owned', $result->sourceCategory);
        $this->assertSame('ppnj_owned', $result->group->source_category);
        $this->assertNull($result->source->ppk_id);
        $this->assertNull($result->ppk);
        $this->assertNull($result->group->weighbridge_centre_id);
        $mapping = $this->find(FfbSourceCodeMapping::class, [
            'ffb_source_group_id' => $result->group->id, 'code' => 'A+B+C',
        ]);
        $this->assertSame($source->id, $mapping->weighbridge_centre_id);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($officialPpksBefore, $this->officialPpkSnapshot());
        $this->assertCount(1, array_filter(
            $this->records[Ppk::class],
            fn (Ppk $ppk): bool => $ppk->entity_type === Ppk::INDEPENDENT_INTEGRATED_ESTATE,
        ));
        $this->assertCount(1, array_filter($this->records[WeighbridgeCentre::class],
            fn ($centre) => $centre->code === 'SRC-LADANG-WAKAF-JEMALUANG'));
        foreach (['A', 'B', 'C'] as $block) {
            $unconfirmed = $this->resolver()->resolve('LADANG WAKAF JEMALUANG', $block);
            $this->assertTrue($unconfirmed->requiresReview);
            $this->assertSame('unknown_contextual_code', $unconfirmed->reviewReason);
            $this->assertNull($unconfirmed->source);
        }
    }

    public static function wakafConflicts(): array
    {
        return [
            'wrong category' => ['source', 'source_category', 'external_supplier', 'SRC-LADANG-WAKAF-JEMALUANG'],
            'non-null PPK' => ['source', 'ppk_id', 1, 'SRC-LADANG-WAKAF-JEMALUANG'],
            'inactive source' => ['source', 'is_active', false, 'SRC-LADANG-WAKAF-JEMALUANG'],
            'wrong heading category' => ['group', 'source_category', 'external_supplier', 'LADANG WAKAF JEMALUANG'],
            'inactive heading' => ['group', 'is_active', false, 'LADANG WAKAF JEMALUANG'],
            'wrong mapped source' => ['mapping', 'weighbridge_centre_id', 1, 'A+B+C'],
            'inactive mapping' => ['mapping', 'is_active', false, 'A+B+C'],
        ];
    }

    #[DataProvider('wakafConflicts')]
    public function test_wakaf_conflicts_abort_without_reclassifying_or_reassigning(
        string $target,
        string $attribute,
        mixed $value,
        string $message,
    ): void {
        $this->seedMockStore();
        $source = $this->find(WeighbridgeCentre::class, ['code' => 'SRC-LADANG-WAKAF-JEMALUANG']);
        $group = $this->find(FfbSourceGroup::class, ['heading' => 'LADANG WAKAF JEMALUANG']);
        $mapping = $this->find(FfbSourceCodeMapping::class, [
            'ffb_source_group_id' => $group->id, 'code' => 'A+B+C',
        ]);
        $model = ['source' => $source, 'group' => $group, 'mapping' => $mapping][$target];
        $model->$attribute = $value;
        $before = $this->snapshot();

        try {
            $this->seedMockStore();
            $this->fail('Conflicting Wakaf master must require review.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_confirmed_codes_parse_with_wakaf_combined_blocks_as_one_owned_ticket(): void
    {
        $this->seedMockStore();
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'Name :');
        $sheet->setCellValue('C1', self::HEADING);
        foreach ([
            [2, 'S/TENGAH', 'P123396', 5.70],
            [3, 'SIMPANG 5 /1', 'P67316', 34.36],
            [4, 'PPK KLUANG', 'P67344', 2.46],
            [6, 'A+B+C', 'P67340', 34.56],
        ] as [$row, $code, $number, $weight]) {
            $sheet->setCellValue('B'.$row, $code);
            $sheet->setCellValue('E'.$row, '28-10-2026');
            $sheet->setCellValue('F'.$row, $number);
            $sheet->setCellValue('H'.$row, '001234');
            $sheet->setCellValue('I'.$row, 'JXS 2033');
            $sheet->setCellValue('K'.$row, $weight);
        }
        $sheet->setCellValue('A5', 'Name :');
        $sheet->setCellValue('C5', 'LADANG WAKAF JEMALUANG');
        $path = tempnam(sys_get_temp_dir(), 'ffb-context-');

        try {
            IOFactory::createWriter($book, 'Xlsx')->save($path);
            $result = (new FfbExcelTicketParser($this->resolver()))->parse($path, 'MT');

            $this->assertSame(4, $result->ticketCount);
            $this->assertSame(77.08, $result->totalMt);
            foreach (array_slice($result->tickets, 0, 3) as $ticket) {
                $this->assertFalse($ticket['requires_review']);
                $this->assertSame('ppnj_ppk_integrated', $ticket['source_resolution']->sourceCategory);
            }
            $wakaf = $result->tickets[3];
            $this->assertSame('LADANG WAKAF JEMALUANG', $wakaf['original_heading']);
            $this->assertSame('A+B+C', $wakaf['source_code']);
            $this->assertSame('P67340', $wakaf['ticket_number']);
            $this->assertSame(34.56, $wakaf['raw_weight']);
            $this->assertSame(34.56, $wakaf['weight_mt']);
            $this->assertFalse($wakaf['requires_review']);
            $this->assertSame('SRC-LADANG-WAKAF-JEMALUANG', $wakaf['source_resolution']->source->code);
            $this->assertNull($wakaf['source_resolution']->source->ppk_id);
            $this->assertNull($wakaf['source_resolution']->ppk);
            $this->assertSame('ppnj_owned', $wakaf['source_resolution']->sourceCategory);
            $this->assertSame([], $wakaf['review_reasons']);
            $this->assertSame([], $result->issues);
            $this->assertFalse($result->requiresReview);
            $this->assertSame('A+B+C', $wakaf['raw_values']['B']);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_conflicting_bis_jaya_alternate_mapping_aborts_without_reassignment(): void
    {
        $this->seedMockStore();
        $group = $this->find(FfbSourceGroup::class, ['heading' => 'BIS JAYA TRADING SDN BHD (A)']);
        $mapping = $this->find(FfbSourceCodeMapping::class, [
            'ffb_source_group_id' => $group->id,
            'code' => 'BIS JAYA (A)',
        ]);
        $mapping->weighbridge_centre_id = $this->find(WeighbridgeCentre::class, ['code' => 'SRC-GUAN-LENG'])->id;
        $before = $this->snapshot();

        try {
            $this->seedMockStore();
            $this->fail('Conflicting alternate mapping must not be reassigned.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('BIS JAYA (A)', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_missing_ppk_stops_seeding_before_any_master_writes(): void
    {
        array_pop($this->records[Ppk::class]);
        $before = $this->snapshot();

        try {
            $this->seedMockStore();
            $this->fail('Missing PPK must not be invented.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('PPK-KLUANG', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_existing_canonical_source_is_not_silently_reclassified(): void
    {
        $this->seedMockStore();
        $source = $this->find(WeighbridgeCentre::class, ['code' => 'SRC-LADANG-KAHANG-2']);
        $source->source_category = 'external_supplier';
        $before = $this->snapshot();

        try {
            $this->seedMockStore();
            $this->fail('Conflicting source must require review.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('SRC-LADANG-KAHANG-2', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_existing_contextual_mapping_is_not_reassigned(): void
    {
        $this->seedMockStore();
        $mapping = $this->records[FfbSourceCodeMapping::class][0];
        $mapping->weighbridge_centre_id = $this->records[WeighbridgeCentre::class][1]->id;
        $before = $this->snapshot();

        try {
            $this->seedMockStore();
            $this->fail('Conflicting code must require review.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('PT RAJA 1', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_conflicting_heading_registration_aborts_without_partial_master_writes(): void
    {
        $this->insert(FfbSourceGroup::class, [
            'heading' => 'LADANG KAHANG 2',
            'source_category' => 'external_supplier',
        ]);
        $before = $this->snapshot();

        try {
            $this->seedMockStore();
            $this->fail('Conflicting heading must require review.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('LADANG KAHANG 2', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_contextual_category_mismatch_and_missing_source_are_reviewed(): void
    {
        $this->seedMockStore();
        $mapping = $this->records[FfbSourceCodeMapping::class][0];
        $source = $this->find(WeighbridgeCentre::class, ['id' => $mapping->weighbridge_centre_id]);
        $source->source_category = 'ppnj_owned';

        $result = $this->resolver()->resolve(self::HEADING, $mapping->code);
        $this->assertTrue($result->requiresReview);
        $this->assertSame('source_category_mismatch', $result->reviewReason);

        $mapping->weighbridge_centre_id = 9999;
        $result = $this->resolver()->resolve(self::HEADING, $mapping->code);
        $this->assertTrue($result->requiresReview);
        $this->assertSame('missing_or_inactive_source', $result->reviewReason);
        $this->assertNull($result->source);
    }

    public function test_disabled_code_mapping_does_not_fall_through_to_direct_heading(): void
    {
        $this->seedMockStore();
        $group = $this->find(FfbSourceGroup::class, ['heading' => 'LADANG KAHANG 2']);
        $this->insert(FfbSourceCodeMapping::class, [
            'ffb_source_group_id' => $group->id,
            'code' => '013A',
            'weighbridge_centre_id' => $group->weighbridge_centre_id,
            'is_active' => false,
        ]);

        $result = $this->resolver()->resolve($group->heading, '013A');

        $this->assertTrue($result->requiresReview);
        $this->assertSame('inactive_code_mapping', $result->reviewReason);
    }

    public function test_save_events_recompute_derived_keys_and_preserve_originals(): void
    {
        $previousDispatcher = Model::getEventDispatcher();
        $dispatcher = new Dispatcher();
        Model::setEventDispatcher($dispatcher);
        Model::clearBootedModels();

        try {
            $group = new FfbSourceGroup(['heading' => ' ladang kahang 2 ']);
            $mapping = new FfbSourceCodeMapping(['code' => ' b/anam 1 ']);
            $group->normalized_heading = 'INCORRECT';
            $mapping->normalized_code = 'INCORRECT';
            $dispatcher->dispatch('eloquent.saving: '.FfbSourceGroup::class, [$group]);
            $dispatcher->dispatch('eloquent.saving: '.FfbSourceCodeMapping::class, [$mapping]);

            $this->assertSame('LADANG KAHANG 2', $group->normalized_heading);
            $this->assertSame('B/ANAM 1', $mapping->normalized_code);
            $this->assertSame(' ladang kahang 2 ', $group->heading);
            $this->assertSame(' b/anam 1 ', $mapping->code);
        } finally {
            Model::clearBootedModels();

            if ($previousDispatcher === null) {
                Model::unsetEventDispatcher();
            } else {
                Model::setEventDispatcher($previousDispatcher);
            }
        }
    }

    public function test_context_models_keep_raw_values_and_derive_guarded_lookup_keys(): void
    {
        $group = new FfbSourceGroup(['heading' => " \tladang kahang 2\n"]);
        $mapping = new FfbSourceCodeMapping(['code' => ' b/anam 1 ']);

        $this->assertSame(" \tladang kahang 2\n", $group->heading);
        $this->assertSame('LADANG KAHANG 2', $group->normalized_heading);
        $this->assertSame(' b/anam 1 ', $mapping->code);
        $this->assertSame('B/ANAM 1', $mapping->normalized_code);
        $this->assertNotContains('normalized_heading', $group->getFillable());
        $this->assertNotContains('normalized_code', $mapping->getFillable());
        $this->assertSame('ffb_source_group_id', $group->codeMappings()->getForeignKeyName());
        $this->assertSame('ffb_source_group_id', $mapping->sourceGroup()->getForeignKeyName());
        $this->assertSame('weighbridge_centre_id', $mapping->weighbridgeCentre()->getForeignKeyName());
        $this->assertSame('weighbridge_centre_id', (new WeighbridgeCentre())->sourceGroups()->getForeignKeyName());
        $this->assertSame('weighbridge_centre_id', (new WeighbridgeCentre())->sourceCodeMappings()->getForeignKeyName());
    }

    public function test_blank_registered_code_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FfbSourceCodeMapping(['code' => " \t"]);
    }

    private function seedMockStore(): void
    {
        (new FfbSourceMappingSeeder())->run(
            $this->models[Ppk::class],
            $this->models[WeighbridgeCentre::class],
            $this->models[FfbSourceGroup::class],
            $this->models[FfbSourceCodeMapping::class],
        );
    }

    private function resolver(): FfbSourceResolver
    {
        return new FfbSourceResolver($this->models[FfbSourceGroup::class], $this->models[FfbSourceCodeMapping::class]);
    }

    private function insert(string $class, array $attributes): Model
    {
        $model = new $class($attributes);
        $model->id = count($this->records[$class]) + 1;
        $this->records[$class][] = $model;

        return $model;
    }

    private function find(string $class, array $conditions): ?Model
    {
        foreach ($this->records[$class] as $model) {
            foreach ($conditions as $key => $value) {
                if ($model->$key !== $value) {
                    continue 2;
                }
            }

            return $model;
        }

        return null;
    }

    private function snapshot(): array
    {
        return array_map(
            fn (array $models): array => array_map(fn (Model $model): array => $model->getAttributes(), $models),
            $this->records,
        );
    }

    private function officialPpkSnapshot(): array
    {
        return array_map(
            fn (Ppk $ppk): array => $ppk->getAttributes(),
            array_values(array_filter(
                $this->records[Ppk::class],
                fn (Ppk $ppk): bool => $ppk->entity_type === Ppk::OFFICIAL_PPK,
            )),
        );
    }

    private function query(string $class): Builder
    {
        $query = Mockery::mock(Builder::class);
        $conditions = [];
        $query->shouldReceive('where')->andReturnUsing(function (string $key, mixed $value) use (&$conditions, $query) {
            $conditions[$key] = $value;

            return $query;
        });
        $query->shouldReceive('with')->with('weighbridgeCentre.ppk')->andReturnSelf();
        $query->shouldReceive('limit')->with(2)->andReturnSelf();
        $query->shouldReceive('first')->andReturnUsing(function () use ($class, &$conditions) {
            return $this->find($class, $conditions);
        });
        $query->shouldReceive('firstOrCreate')->andReturnUsing(function (array $attributes, array $values) use ($class, &$conditions) {
            return $this->find($class, array_merge($conditions, $attributes))
                ?? $this->insert($class, array_merge($attributes, $values));
        });
        $query->shouldReceive('get')->andReturnUsing(function () use ($class, &$conditions) {
            $found = [];

            foreach ($this->records[$class] as $record) {
                foreach ($conditions as $key => $value) {
                    if ($record->$key !== $value) {
                        continue 2;
                    }
                }

                $centre = $record->weighbridge_centre_id === null ? null
                    : $this->find(WeighbridgeCentre::class, ['id' => $record->weighbridge_centre_id]);

                if ($centre) {
                    $centre->setRelation('ppk', $centre->ppk_id === null ? null
                        : $this->find(Ppk::class, ['id' => $centre->ppk_id]));
                }

                $record->setRelation('weighbridgeCentre', $centre);
                $found[] = $record;
            }

            return new Collection(array_slice($found, 0, 2));
        });

        return $query;
    }
}
