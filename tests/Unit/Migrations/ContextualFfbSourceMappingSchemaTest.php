<?php

namespace Tests\Unit\Migrations;

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
use PHPUnit\Framework\TestCase;

class ContextualFfbSourceMappingSchemaTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ?ContainerContract $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstance('db.schema');
        Facade::setFacadeApplication(new Container());
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('db.schema');
        Facade::setFacadeApplication($this->previousApplication);
        parent::tearDown();
    }

    public function test_contextual_schema_has_unique_keys_restrictive_foreign_keys_and_no_mill_scope(): void
    {
        $schema = Mockery::mock(Builder::class);
        Schema::swap($schema);
        $statements = [];
        $connection = new MySqlConnection(function () {
            throw new LogicException('Database access is forbidden in this unit test.');
        }, '', '', [
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'version' => '8.0.0',
        ]);
        $connection->useDefaultSchemaGrammar();

        foreach (['ffb_source_groups', 'ffb_source_code_mappings'] as $table) {
            $schema->shouldReceive('create')->once()->ordered()
                ->with($table, Mockery::type(Closure::class))
                ->andReturnUsing(function (string $name, Closure $callback) use ($connection, &$statements): void {
                    $blueprint = new Blueprint($connection, $name);
                    $blueprint->create();
                    $callback($blueprint);
                    $statements[$name] = implode("\n", $blueprint->toSql());
                });
        }

        // Capture declarations and compile SQL only; no blueprint is executed.
        $this->migration()->up();
        $groups = $statements['ffb_source_groups'];
        $mappings = $statements['ffb_source_code_mappings'];

        $this->assertStringContainsString('unique `ffb_source_groups_normalized_heading_unique`(`normalized_heading`)', $groups);
        $this->assertStringContainsString('`heading` varchar(255) not null', $groups);
        $this->assertStringContainsString('`weighbridge_centre_id` bigint unsigned null', $groups);
        $this->assertStringContainsString("enum('ppnj_owned', 'ppnj_ppk_integrated', 'external_supplier')", $groups);
        $this->assertStringContainsString('unique `ffb_contextual_code_unique`(`ffb_source_group_id`, `normalized_code`)', $mappings);
        $this->assertStringContainsString('`code` varchar(255) not null', $mappings);
        $this->assertStringContainsString('foreign key (`ffb_source_group_id`) references `ffb_source_groups` (`id`) on delete restrict', $mappings);

        foreach ([$groups, $mappings] as $sql) {
            $this->assertStringContainsString("collate 'utf8mb4_bin'", $sql);
            $this->assertStringContainsString('references `weighbridge_centres` (`id`) on delete restrict', $sql);
            $this->assertStringContainsString('`is_active` tinyint(1) not null default \'1\'', $sql);
            $this->assertStringNotContainsString('cascade', $sql);
            $this->assertStringNotContainsString('mill_id', $sql);
        }
    }

    public function test_down_drops_mapping_children_before_groups(): void
    {
        $schema = Mockery::mock(Builder::class);
        Schema::swap($schema);
        $schema->shouldReceive('dropIfExists')->once()->ordered()->with('ffb_source_code_mappings');
        $schema->shouldReceive('dropIfExists')->once()->ordered()->with('ffb_source_groups');

        $this->migration()->down();
    }

    private function migration(): Migration
    {
        return require dirname(__DIR__, 3)
            .'/database/migrations/2026_10_30_000005_create_contextual_ffb_source_mapping_tables.php';
    }
}
