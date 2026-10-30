<?php

namespace Tests\Unit\Migrations;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AddMissingOperationColumnsMigrationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

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

    public function test_missing_table_is_left_untouched(): void
    {
        $this->schema->shouldReceive('hasTable')->once()
            ->with('daily_operations')->andReturn(false);

        $this->migration()->up();
    }

    public static function columnStates(): array
    {
        return [
            'both missing' => [false, false],
            'status already exists' => [true, false],
            'note already exists' => [false, true],
            'both already exist' => [true, true],
        ];
    }

    #[DataProvider('columnStates')]
    public function test_only_missing_columns_are_added(
        bool $statusExists,
        bool $noteExists
    ): void {
        $this->schema->shouldReceive('hasTable')->once()
            ->with('daily_operations')->andReturn(true);
        $this->schema->shouldReceive('hasColumn')->once()
            ->with('daily_operations', 'operation_status')->andReturn($statusExists);
        $this->schema->shouldReceive('hasColumn')->once()
            ->with('daily_operations', 'operation_note')->andReturn($noteExists);

        if (! $statusExists) {
            $column = Mockery::mock(ColumnDefinition::class);
            $column->shouldReceive('default')->once()->with('Operasi')->andReturnSelf();

            $blueprint = Mockery::mock(Blueprint::class);
            $blueprint->shouldReceive('enum')->once()
                ->with('operation_status', ['Operasi', 'Tidak Operasi (Terima Buah Sahaja)'])
                ->andReturn($column);

            $this->expectTableChange($blueprint);
        }

        if (! $noteExists) {
            $column = Mockery::mock(ColumnDefinition::class);
            $column->shouldReceive('nullable')->once()->withNoArgs()->andReturnSelf();

            $blueprint = Mockery::mock(Blueprint::class);
            $blueprint->shouldReceive('text')->once()
                ->with('operation_note')->andReturn($column);

            $this->expectTableChange($blueprint);
        }

        $this->migration()->up();
    }

    public function test_repeated_up_does_not_change_existing_columns(): void
    {
        $this->schema->shouldReceive('hasTable')->twice()
            ->with('daily_operations')->andReturn(true);
        $this->schema->shouldReceive('hasColumn')->twice()
            ->with('daily_operations', 'operation_status')->andReturn(true);
        $this->schema->shouldReceive('hasColumn')->twice()
            ->with('daily_operations', 'operation_note')->andReturn(true);

        $migration = $this->migration();
        $migration->up();
        $migration->up();
    }

    public function test_down_does_not_access_or_change_the_schema(): void
    {
        $this->migration()->down();

        $this->addToAssertionCount(1);
    }

    private function expectTableChange(MockInterface $blueprint): void
    {
        $this->schema->shouldReceive('table')->once()->ordered()
            ->with('daily_operations', Mockery::type(Closure::class))
            ->andReturnUsing(function (string $table, Closure $callback) use ($blueprint): void {
                $callback($blueprint);
            });
    }

    private function migration(): Migration
    {
        return require dirname(__DIR__, 3)
            . '/database/migrations/2026_10_30_000001_add_missing_operation_columns_to_daily_operations_table.php';
    }
}
