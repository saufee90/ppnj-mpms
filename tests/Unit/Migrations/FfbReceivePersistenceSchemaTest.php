<?php

namespace Tests\Unit\Migrations;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FfbReceivePersistenceSchemaTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const MIGRATIONS = [
        'ffb_import_batches' => '2026_11_03_000001_create_ffb_import_batches_table.php',
        'ffb_weighbridge_tickets' => '2026_11_03_000002_create_ffb_weighbridge_tickets_table.php',
        'daily_operations' => '2026_11_03_000003_add_ffb_import_batch_id_to_daily_operations_table.php',
    ];

    private ?ContainerContract $previousApplication;

    private MockInterface $schema;

    private MockInterface $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstance('db.schema');
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication(new Container());
        $this->schema = Mockery::mock(Builder::class);
        $this->database = Mockery::mock(DatabaseManager::class);
        Schema::swap($this->schema);
        DB::swap($this->database);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('db.schema');
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication($this->previousApplication);
        parent::tearDown();
    }

    public function test_batches_preserve_revisions_and_allow_only_one_current_confirmation(): void
    {
        $check = '';
        $this->database->shouldReceive('statement')->once()
            ->andReturnUsing(function (string $sql) use (&$check): bool {
                $check = $sql;

                return true;
            });
        $sql = $this->definitionSql('ffb_import_batches');

        $this->assertStringContainsString('`parsed_total_mt` decimal(15, 6) null', $sql);
        $this->assertStringContainsString('`control_total_mt` decimal(15, 6) null', $sql);
        $this->assertStringContainsString('`confirmed_daily_total_mt` decimal(10, 2) null', $sql);
        $this->assertStringContainsString('`confirmation_snapshot` json null', $sql);
        $this->assertStringContainsString('`reconciliation_evidence` json null', $sql);
        $this->assertStringContainsString('`validation_summary` json null', $sql);
        $this->assertStringContainsString('`file_sha256` char(64) not null', $sql);
        $this->assertStringContainsString('index `ffb_import_batches_file_sha256_index`(`file_sha256`)', $sql);
        $this->assertStringNotContainsString('unique `ffb_import_batches_file_sha256', $sql);
        $this->assertStringContainsString("as (CASE WHEN status = 'confirmed' THEN 1 ELSE NULL END)", $sql);
        $this->assertStringContainsString(
            'unique `ffb_batches_current_unique`(`mill_id`, `receipt_date`, `active_slot`)',
            $sql,
        );
        foreach ([
            'mill_id' => 'mills',
            'uploaded_by' => 'users',
            'confirmed_by' => 'users',
            'supersedes_batch_id' => 'ffb_import_batches',
            'daily_operation_id' => 'daily_operations',
        ] as $key => $table) {
            $this->assertStringContainsString(
                "foreign key (`{$key}`) references `{$table}` (`id`) on delete restrict",
                $sql,
            );
        }
        foreach ([
            "status NOT IN ('confirmed', 'superseded')",
            'confirmed_by IS NOT NULL', 'confirmed_at IS NOT NULL',
            'confirmed_daily_total_mt IS NOT NULL', 'confirmed_daily_total_mt >= 0',
            'confirmation_snapshot IS NOT NULL', 'validated_at IS NOT NULL',
            'preview_hash IS NOT NULL',
        ] as $condition) {
            $this->assertStringContainsString($condition, $check);
        }
        $this->assertStringNotContainsString('cascade', $sql);
    }

    public function test_tickets_keep_invalid_candidates_originals_and_source_snapshots(): void
    {
        $sql = $this->definitionSql('ffb_weighbridge_tickets');

        foreach (['original_heading', 'original_source_code'] as $column) {
            $this->assertStringContainsString("`{$column}` text null", $sql);
        }
        foreach (['ticket_number', 'do_number', 'vehicle_number'] as $column) {
            $this->assertStringContainsString("`{$column}` varchar(255) null", $sql);
        }
        $this->assertStringContainsString('`raw_values` json not null', $sql);
        $this->assertStringContainsString('`resolution_snapshot` json null', $sql);
        $this->assertStringContainsString('`review_reasons` json null', $sql);
        $this->assertStringContainsString('`ticket_date` date null', $sql);
        $this->assertStringContainsString('`weight_mt` decimal(15, 6) null', $sql);
        $this->assertStringContainsString(
            '`worksheet` varchar(31) character set utf8mb4 collate \'utf8mb4_bin\' not null',
            $sql,
        );
        $this->assertStringContainsString(
            'unique `ffb_tickets_batch_location_unique`(`ffb_import_batch_id`, `worksheet`, `row_number`)',
            $sql,
        );
        $this->assertStringContainsString(
            'index `ffb_tickets_batch_number_index`(`ffb_import_batch_id`, `normalized_ticket_number`)',
            $sql,
        );
        foreach ([
            'ffb_import_batch_id' => 'ffb_import_batches',
            'ffb_source_group_id' => 'ffb_source_groups',
            'weighbridge_centre_id' => 'weighbridge_centres',
            'ppk_id' => 'ppks',
        ] as $key => $table) {
            $this->assertStringContainsString(
                "foreign key (`{$key}`) references `{$table}` (`id`) on delete restrict",
                $sql,
            );
        }
        $this->assertStringNotContainsString('cascade', $sql);
        $this->assertStringNotContainsString('mill_id', $sql);
    }

    public function test_daily_provenance_is_nullable_unique_and_has_no_data_backfill(): void
    {
        $sql = $this->definitionSql('daily_operations');

        $this->assertStringContainsString('add `ffb_import_batch_id` bigint unsigned null', $sql);
        $this->assertStringContainsString(
            'unique `daily_operations_ffb_import_batch_id_unique`(`ffb_import_batch_id`)',
            $sql,
        );
        $this->assertStringContainsString(
            'foreign key (`ffb_import_batch_id`) references `ffb_import_batches` (`id`) on delete restrict',
            $sql,
        );
        $this->assertStringNotContainsString('bts_diterima', $sql);
        $this->assertStringNotContainsString('update ', $sql);
    }

    public static function sqliteTables(): array
    {
        return [
            'batches' => ['ffb_import_batches'],
            'tickets' => ['ffb_weighbridge_tickets'],
        ];
    }

    #[DataProvider('sqliteTables')]
    public function test_sqlite_create_definitions_compile_without_database_access(string $table): void
    {
        $sql = $this->definitionSql($table, 'sqlite');

        $this->assertStringContainsString('create table "'.$table.'"', $sql);
        $this->assertStringContainsString('on delete restrict', $sql);
        $this->assertStringNotContainsString('utf8mb4_bin', $sql);
        if ($table === 'ffb_import_batches') {
            $this->assertStringContainsString("as (CASE WHEN status = 'confirmed' THEN 1 ELSE NULL END)", $sql);
            $this->assertStringContainsString(
                'unique index "ffb_batches_current_unique" on "ffb_import_batches" ("mill_id", "receipt_date", "active_slot")',
                $sql,
            );
        }
    }

    public function test_rollback_removes_provenance_before_ticket_and_batch_tables(): void
    {
        $this->schema->shouldReceive('table')->once()->ordered()
            ->with('daily_operations', Mockery::type(Closure::class))
            ->andReturnUsing(function (string $name, Closure $callback): void {
                $blueprint = Mockery::mock(Blueprint::class);
                $blueprint->shouldReceive('dropForeign')->once()->ordered()->with(['ffb_import_batch_id']);
                $blueprint->shouldReceive('dropUnique')->once()->ordered()->with(['ffb_import_batch_id']);
                $blueprint->shouldReceive('dropColumn')->once()->ordered()->with('ffb_import_batch_id');
                $callback($blueprint);
            });
        $this->schema->shouldReceive('dropIfExists')->once()->ordered()->with('ffb_weighbridge_tickets');
        $this->schema->shouldReceive('dropIfExists')->once()->ordered()->with('ffb_import_batches');

        foreach (array_reverse(array_keys(self::MIGRATIONS)) as $table) {
            $this->migration($table)->down();
        }
    }

    private function definitionSql(string $table, string $driver = 'mysql'): string
    {
        $noDatabase = function () {
            throw new LogicException('Database access is forbidden in this unit test.');
        };
        if ($driver === 'mysql') {
            $connection = new class($noDatabase, '', '', [
                'driver' => 'mysql', 'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci', 'version' => '8.4.3',
            ]) extends MySqlConnection {
                public function isMaria()
                {
                    return false;
                }

                public function getServerVersion(): string
                {
                    return '8.4.3';
                }
            };
        } else {
            $connection = new SQLiteConnection($noDatabase, ':memory:', '', [
                'driver' => 'sqlite', 'version' => '3.45.0',
            ]);
        }
        $connection->useDefaultSchemaGrammar();
        $this->schema->shouldReceive('getConnection')->andReturn($connection);

        return $this->captureDefinition($table, $connection);
    }

    private function captureDefinition(string $table, Connection $connection): string
    {
        $sql = '';
        $method = $table === 'daily_operations' ? 'table' : 'create';
        $this->schema->shouldReceive($method)->once()
            ->with($table, Mockery::type(Closure::class))
            ->andReturnUsing(function (string $name, Closure $callback) use ($connection, $method, &$sql): void {
                $blueprint = new Blueprint($connection, $name);
                if ($method === 'create') {
                    $blueprint->create();
                }
                $callback($blueprint);
                $sql = implode("\n", $blueprint->toSql());
            });

        // All schema/statement calls are intercepted; only declarations are compiled.
        $this->migration($table)->up();

        return $sql;
    }

    private function migration(string $table): Migration
    {
        return require dirname(__DIR__, 3).'/database/migrations/'.self::MIGRATIONS[$table];
    }
}
