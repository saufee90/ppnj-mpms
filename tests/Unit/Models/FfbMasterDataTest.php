<?php

namespace Tests\Unit\Models;

use App\Models\FfbSourceAlias;
use App\Models\Ppk;
use App\Models\WeighbridgeCentre;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\MySqlConnection;
use Illuminate\Events\Dispatcher;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FfbMasterDataTest extends TestCase
{
    private ?ConnectionResolverInterface $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousResolver = Model::getConnectionResolver();
        $connection = new MySqlConnection(function () {
            throw new LogicException('Database access is forbidden in this unit test.');
        });
        $resolver = new ConnectionResolver(['unit' => $connection]);
        $resolver->setDefaultConnection('unit');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($this->previousResolver);
        }

        parent::tearDown();
    }

    public function test_ppk_has_many_centres_and_centre_belongs_to_ppk(): void
    {
        $ppk = new Ppk(['code' => 'SIMPANG-LIMA', 'name' => 'PPK Simpang Lima']);
        $ppk->id = 1;
        $centres = $ppk->weighbridgeCentres();

        $this->assertInstanceOf(HasMany::class, $centres);
        $this->assertInstanceOf(WeighbridgeCentre::class, $centres->getRelated());
        $this->assertSame('ppk_id', $centres->getForeignKeyName());
        $this->assertSame('id', $centres->getLocalKeyName());

        $centre = new WeighbridgeCentre(['ppk_id' => '1']);
        $parent = $centre->ppk();

        $this->assertInstanceOf(BelongsTo::class, $parent);
        $this->assertInstanceOf(Ppk::class, $parent->getRelated());
        $this->assertSame('ppk_id', $parent->getForeignKeyName());
        $this->assertSame('id', $parent->getOwnerKeyName());
        $this->assertSame(1, $centre->ppk_id);
    }

    public function test_centre_has_multiple_aliases_and_alias_belongs_to_centre(): void
    {
        $centre = new WeighbridgeCentre(['code' => 'SL2', 'name' => 'Simpang Lima 2']);
        $centre->id = 2;
        $aliases = $centre->sourceAliases();

        $this->assertInstanceOf(HasMany::class, $aliases);
        $this->assertInstanceOf(FfbSourceAlias::class, $aliases->getRelated());
        $this->assertSame('weighbridge_centre_id', $aliases->getForeignKeyName());

        $first = new FfbSourceAlias(['alias_code' => 'B/UTARA 2']);
        $second = new FfbSourceAlias(['alias_code' => 'SPG. LIMA 2']);
        $first->weighbridgeCentre()->associate($centre);
        $second->weighbridgeCentre()->associate($centre);

        $this->assertSame(2, $first->weighbridge_centre_id);
        $this->assertSame(2, $second->weighbridge_centre_id);
        $this->assertSame($centre, $first->weighbridgeCentre);
        $this->assertSame($centre, $second->weighbridgeCentre);
        $this->assertInstanceOf(BelongsTo::class, $first->weighbridgeCentre());
    }

    public function test_centre_can_have_no_parent_ppk(): void
    {
        $centre = new WeighbridgeCentre([
            'ppk_id' => null,
            'source_category' => 'external_supplier',
        ]);

        $this->assertNull($centre->ppk_id);
        $this->assertNull($centre->ppk);
        $this->assertArrayNotHasKey('mill_id', $centre->getAttributes());
        $this->assertNotContains('mill_id', $centre->getFillable());
    }

    public function test_classifications_are_exactly_the_three_supported_values(): void
    {
        $this->assertSame([
            'ppnj_owned',
            'ppnj_ppk_integrated',
            'external_supplier',
        ], WeighbridgeCentre::SOURCE_CATEGORIES);

        foreach (WeighbridgeCentre::SOURCE_CATEGORIES as $category) {
            $centre = new WeighbridgeCentre(['source_category' => $category]);

            $this->assertSame($category, $centre->source_category);
        }
    }

    public function test_active_flags_default_to_true_and_are_boolean_casts(): void
    {
        foreach ([new Ppk(), new WeighbridgeCentre(), new FfbSourceAlias()] as $model) {
            $this->assertTrue($model->is_active);
            $model->is_active = 0;
            $this->assertFalse($model->is_active);
        }
    }

    public static function aliasCodes(): array
    {
        return [
            'case and surrounding whitespace' => [" \tb/utara 2\r\n", 'B/UTARA 2'],
            'period retained' => ['spg. lima 2', 'SPG. LIMA 2'],
            'internal whitespace retained' => ['SPG.  LIMA 2', 'SPG.  LIMA 2'],
            'third centre retained' => ['b.utara 3', 'B.UTARA 3'],
        ];
    }

    #[DataProvider('aliasCodes')]
    public function test_normalization_preserves_original_alias(string $raw, string $normalized): void
    {
        $alias = new FfbSourceAlias(['alias_code' => $raw]);

        $this->assertSame($raw, $alias->alias_code);
        $this->assertSame($normalized, $alias->normalized_code);
        $this->assertSame($normalized, FfbSourceAlias::normalizeCode($raw));
        $this->assertSame($normalized, FfbSourceAlias::normalizeCode($normalized));
    }

    public function test_equivalent_aliases_share_a_lookup_key_but_distinct_codes_do_not(): void
    {
        $first = new FfbSourceAlias(['alias_code' => 'B/UTARA 2']);
        $duplicate = new FfbSourceAlias(['alias_code' => ' b/utara 2 ']);
        $other = new FfbSourceAlias(['alias_code' => 'B.UTARA 3']);

        $this->assertSame($first->normalized_code, $duplicate->normalized_code);
        $this->assertNotSame($first->normalized_code, $other->normalized_code);
        $this->assertNotSame(
            FfbSourceAlias::normalizeCode('B/UTARA 2'),
            FfbSourceAlias::normalizeCode('B.UTARA 2'),
        );
    }

    public function test_editing_alias_recomputes_lookup_key_and_key_is_not_mass_assignable(): void
    {
        $alias = new FfbSourceAlias(['alias_code' => 'B/UTARA 2']);
        $alias->alias_code = ' spg. lima 2 ';

        $this->assertSame(' spg. lima 2 ', $alias->alias_code);
        $this->assertSame('SPG. LIMA 2', $alias->normalized_code);
        $this->assertNotContains('normalized_code', $alias->getFillable());
    }

    public function test_saving_event_restores_lookup_key_without_changing_original_alias(): void
    {
        $previousDispatcher = Model::getEventDispatcher();
        $dispatcher = new Dispatcher();
        Model::setEventDispatcher($dispatcher);
        FfbSourceAlias::clearBootedModels();

        try {
            $alias = new FfbSourceAlias(['alias_code' => ' b/utara 2 ']);
            $alias->normalized_code = 'UNRELATED';

            $dispatcher->dispatch('eloquent.saving: '.FfbSourceAlias::class, [$alias]);

            $this->assertSame('B/UTARA 2', $alias->normalized_code);
            $this->assertSame(' b/utara 2 ', $alias->alias_code);
        } finally {
            FfbSourceAlias::clearBootedModels();

            if ($previousDispatcher === null) {
                Model::unsetEventDispatcher();
            } else {
                Model::setEventDispatcher($previousDispatcher);
            }
        }
    }

    public function test_blank_alias_is_rejected_explicitly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FfbSourceAlias(['alias_code' => " \t\r\n"]);
    }
}
