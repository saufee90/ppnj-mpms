<?php

namespace Tests\Unit\Services;

use App\Models\FfbSourceAlias;
use App\Models\FfbSourceCodeMapping;
use App\Models\FfbSourceGroup;
use App\Models\Ppk;
use App\Models\WeighbridgeCentre;
use App\Services\FfbSourceResolver;
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

        $this->assertCount(13, array_unique($sourceIds));
        $this->assertCount(8, $this->records[Ppk::class]);
    }

    public function test_owned_estates_keep_block_codes_as_attributes_not_global_aliases(): void
    {
        $this->seedMockStore();

        foreach ([
            'LADANG BUKIT BUJANG' => 'Ladang Bukit Bujang',
            'LADANG KAHANG 2' => 'Ladang Kahang 2',
            'LADANG KONSORTIUM PPNJ/PPK' => 'Ladang Konsortium PPNJ/PPK',
        ] as $heading => $name) {
            $ids = [];

            foreach (['013A', '018A', '04A', 'PH 1D', 'PH 2B', '23B'] as $block) {
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

        $this->assertCount(13, $this->records[FfbSourceCodeMapping::class]);
    }

    public function test_integrated_estates_remain_separate_without_forced_ppk(): void
    {
        $this->seedMockStore();
        $ids = [];

        foreach ([
            'LADANG USAHASAMA PPNJ / JELAPANG MEGAH',
            'LADANG USAHASAMA PPNJ / PPK KLUANG UTARA',
        ] as $heading) {
            $result = $this->resolver()->resolve($heading, '04A');

            $this->assertFalse($result->requiresReview);
            $this->assertSame('ppnj_ppk_integrated', $result->sourceCategory);
            $this->assertNull($result->ppk);
            $this->assertNull($result->source->ppk_id);
            $ids[] = $result->source->id;
        }

        $this->assertCount(2, array_unique($ids));
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
            'LADANG USAHASAMA PKAYIS&KDSSB',
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
            'unconfirmed alternate heading' => ['BIS JAYA TRADING SDN BHD (A)', '013A', 'unknown_heading'],
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

    public function test_seeding_twice_is_idempotent_and_does_not_create_ppks_or_block_aliases(): void
    {
        $this->seedMockStore();
        $before = $this->snapshot();
        $this->seedMockStore();

        $this->assertSame($before, $this->snapshot());
        $this->assertCount(8, $this->records[Ppk::class]);
        $this->assertCount(26, $this->records[WeighbridgeCentre::class]);
        $this->assertCount(14, $this->records[FfbSourceGroup::class]);
        $this->assertCount(13, $this->records[FfbSourceCodeMapping::class]);
        $this->assertArrayNotHasKey(FfbSourceAlias::class, $this->models);
    }

    public function test_missing_ppk_stops_seeding_before_any_master_writes(): void
    {
        array_pop($this->records[Ppk::class]);
        $before = $this->snapshot();

        try {
            $this->seedMockStore();
            $this->fail('Missing PPK must not be invented.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('PPK-LEDANG-TANGKAK', $exception->getMessage());
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
