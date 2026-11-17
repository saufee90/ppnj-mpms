<?php

namespace Tests\Unit\Migrations;

use App\Models\WeighbridgeCentre;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class FfbMasterDataSchemaTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const MIGRATIONS = [
        'ppks' => '2026_10_30_000002_create_ppks_table.php',
        'weighbridge_centres' => '2026_10_30_000003_create_weighbridge_centres_table.php',
        'ffb_source_aliases' => '2026_10_30_000004_create_ffb_source_aliases_table.php',
    ];

    private ?ContainerContract $previousApplication;

    private MockInterface $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstance('db.schema');
        Facade::setFacadeApplication(new Container());
        $this->schema = Mockery::mock(Builder::class);
        Schema::swap($this->schema);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('db.schema');
        Facade::setFacadeApplication($this->previousApplication);

        parent::tearDown();
    }

    public function test_ppks_have_unique_codes_and_active_default(): void
    {
        $sql = $this->definitionSql('ppks');

        $this->assertStringContainsString('`code` varchar(255) not null', $sql);
        $this->assertStringContainsString('unique `ppks_code_unique`(`code`)', $sql);
        $this->assertStringContainsString('`name` varchar(255) not null', $sql);
        $this->assertStringContainsString('`is_active` tinyint(1) not null default \'1\'', $sql);
        $this->assertStringContainsString('`created_at` timestamp null', $sql);
        $this->assertStringContainsString('`updated_at` timestamp null', $sql);
    }

    public function test_centres_have_nullable_restrictive_parent_and_exact_categories(): void
    {
        $sql = $this->definitionSql('weighbridge_centres');
        $categories = implode(', ', array_map(
            fn (string $category): string => "'{$category}'",
            WeighbridgeCentre::SOURCE_CATEGORIES,
        ));

        $this->assertStringContainsString('`ppk_id` bigint unsigned null', $sql);
        $this->assertStringContainsString('foreign key (`ppk_id`) references `ppks` (`id`) on delete restrict', $sql);
        $this->assertStringContainsString('unique `weighbridge_centres_code_unique`(`code`)', $sql);
        $this->assertStringContainsString("`source_category` enum({$categories}) not null", $sql);
        $this->assertStringContainsString('(`ppk_id`, `is_active`)', $sql);
        $this->assertStringContainsString('`is_active` tinyint(1) not null default \'1\'', $sql);
        $this->assertStringNotContainsString('mill_id', $sql);
        $this->assertStringNotContainsString('cascade', $sql);
    }

    public function test_normalized_aliases_have_global_unique_binary_lookup_keys(): void
    {
        $sql = $this->definitionSql('ffb_source_aliases');

        $this->assertStringContainsString('`alias_code` varchar(255) not null', $sql);
        $this->assertStringContainsString(
            '`normalized_code` varchar(255) character set utf8mb4 collate \'utf8mb4_bin\' not null',
            $sql,
        );
        $this->assertStringContainsString(
            'unique `ffb_source_aliases_normalized_code_unique`(`normalized_code`)',
            $sql,
        );
        $this->assertStringContainsString(
            'foreign key (`weighbridge_centre_id`) references `weighbridge_centres` (`id`) on delete restrict',
            $sql,
        );
        $this->assertStringContainsString('(`weighbridge_centre_id`, `is_active`)', $sql);
        $this->assertStringContainsString('`is_active` tinyint(1) not null default \'1\'', $sql);
        $this->assertStringNotContainsString('cascade', $sql);
    }

    public function test_reverse_timestamp_order_drops_children_before_parents(): void
    {
        foreach (array_reverse(array_keys(self::MIGRATIONS)) as $table) {
            $this->schema->shouldReceive('dropIfExists')->once()->ordered()->with($table);
            $this->migration($table)->down();
        }
    }

    private function definitionSql(string $table): string
    {
        $sql = '';
        $connection = new MySqlConnection(function () {
            throw new LogicException('Database access is forbidden in this unit test.');
        }, '', '', [
            'driver' => 'mysql',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'version' => '8.0.0',
        ]);
        $connection->useDefaultSchemaGrammar();
        $this->schema->shouldReceive('getConnection')->andReturn($connection);

        // Capture and compile declarations only; never build a blueprint or open PDO.
        $this->schema->shouldReceive('create')->once()
            ->with($table, Mockery::type(Closure::class))
            ->andReturnUsing(function (string $name, Closure $callback) use ($connection, &$sql): void {
                $blueprint = new Blueprint($connection, $name);
                $blueprint->create();
                $callback($blueprint);
                $sql = implode("\n", $blueprint->toSql());
            });

        $this->migration($table)->up();

        return $sql;
    }

    private function migration(string $table): Migration
    {
        return require dirname(__DIR__, 3).'/database/migrations/'.self::MIGRATIONS[$table];
    }
}
