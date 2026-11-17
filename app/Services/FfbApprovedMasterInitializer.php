<?php

namespace App\Services;

use App\Models\Ppk;
use App\Models\WeighbridgeCentre;
use Database\Seeders\FfbSourceMappingSeeder;
use Database\Seeders\PpkMasterSeeder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FfbApprovedMasterInitializer
{
    private const MASTER_TABLES = [
        'ppks',
        'weighbridge_centres',
        'ffb_source_aliases',
        'ffb_source_groups',
        'ffb_source_code_mappings',
    ];

    private const OPERATIONAL_TABLES = [
        'daily_operations',
        'ffb_import_batches',
        'ffb_weighbridge_tickets',
        'audit_logs',
    ];

    private const REQUIRED_COLUMNS = [
        'ppks' => ['id', 'code', 'name', 'entity_type', 'is_active'],
        'weighbridge_centres' => ['id', 'ppk_id', 'code', 'name', 'source_category', 'is_active'],
        'ffb_source_aliases' => ['id', 'weighbridge_centre_id', 'alias_code', 'normalized_code', 'is_active'],
        'ffb_source_groups' => ['id', 'heading', 'normalized_heading', 'source_category', 'weighbridge_centre_id', 'is_active'],
        'ffb_source_code_mappings' => ['id', 'ffb_source_group_id', 'code', 'normalized_code', 'weighbridge_centre_id', 'is_active'],
        'mills' => ['id', 'code'],
        'daily_operations' => ['id', 'mill_id', 'tarikh', 'ffb_import_batch_id'],
        'ffb_import_batches' => ['id', 'mill_id', 'receipt_date', 'status'],
        'ffb_weighbridge_tickets' => ['id', 'ffb_import_batch_id', 'weighbridge_centre_id', 'ppk_id'],
        'audit_logs' => ['id', 'action'],
    ];

    private const REQUIRED_FOREIGN_KEYS = [
        'weighbridge_centres' => ['ppk_id'],
        'ffb_source_aliases' => ['weighbridge_centre_id'],
        'ffb_source_groups' => ['weighbridge_centre_id'],
        'ffb_source_code_mappings' => ['ffb_source_group_id', 'weighbridge_centre_id'],
    ];

    private const MAX_MASTER_COUNTS = [
        'ppks' => 30,
        'weighbridge_centres' => 31,
        'ffb_source_aliases' => 0,
        'ffb_source_groups' => 17,
        'ffb_source_code_mappings' => 24,
    ];

    public function initialize(bool $dryRun = false): array
    {
        $connection = $this->assertTargetConnection();
        $this->assertSchema($connection);

        $before = $this->operationalSnapshot($connection);
        $masterCountsBefore = $this->masterCounts($connection);
        $plan = $this->masterPlan($connection);

        if ($dryRun) {
            return [
                'dry_run' => true,
                'master_counts_before' => $masterCountsBefore,
                'master_counts_after' => $plan['expected_final_counts'],
                'plan' => $plan,
                'operational_before' => $before,
                'operational_after' => $before,
            ];
        }

        return DB::transaction(function () use ($connection, $before, $masterCountsBefore, $plan): array {
            $this->assertMasterCountsUnchanged($masterCountsBefore);
            if ($this->masterPlan($connection, true) !== $plan) {
                throw new RuntimeException('Master records changed after preflight; initialization aborted.');
            }
            (new PpkMasterSeeder())->run();
            app()->call([new FfbSourceMappingSeeder(), 'run']);
            $this->assertFinalMasterCounts($connection);
            $finalPlan = $this->masterPlan($connection, true);
            foreach ($finalPlan['inserts'] as $missing) {
                if ($missing !== []) {
                    throw new RuntimeException('Initialized masters do not match the approved plan.');
                }
            }

            $after = $this->operationalSnapshot($connection);
            if ($before !== $after) {
                throw new RuntimeException('Operational row counts or hashes changed during master initialization; transaction aborted.');
            }

            return [
                'dry_run' => false,
                'master_counts_before' => $masterCountsBefore,
                'master_counts_after' => $this->masterCounts($connection),
                'plan' => $plan,
                'operational_before' => $before,
                'operational_after' => $after,
            ];
        });
    }

    private function assertTargetConnection(): Connection
    {
        $connection = DB::connection();
        $targets = [
            'stage3-test-app' => 'mpms_ffb_stage3_test',
            'master-uat' => 'mpms_ffb_master_uat',
        ];
        $expectedDatabase = $targets[app()->environment()] ?? null;
        if ($expectedDatabase === null
            || DB::getDefaultConnection() !== 'mysql'
            || $connection->getName() !== 'mysql'
            || $connection->getDriverName() !== 'mysql'
            || ! in_array($connection->getConfig('host'), ['localhost', '127.0.0.1'], true)
            || $connection->getDatabaseName() !== $expectedDatabase
            || config('database.connections.mysql.database') !== $expectedDatabase) {
            throw new RuntimeException('Initialization blocked: the local MySQL 8.4.3 environment/database pair is not approved.');
        }
        $details = $connection->selectOne(
            'SELECT DATABASE() AS database_name, VERSION() AS version, CURRENT_USER() AS account'
        );
        if ($details->database_name !== $expectedDatabase || ! str_starts_with($details->version, '8.4.3')) {
            throw new RuntimeException('Initialization blocked: the local MySQL 8.4.3 environment/database pair is not approved.');
        }

        return $connection;
    }

    private function assertSchema(Connection $connection): void
    {
        $tables = [...self::MASTER_TABLES, ...self::OPERATIONAL_TABLES, 'mills'];
        $metadata = $connection->select(
            'SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES '
                .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.implode(',', array_fill(0, count($tables), '?')).')',
            $tables,
        );
        $engines = [];
        foreach ($metadata as $table) {
            $engines[$table->table_name] = $table->engine;
        }

        $tableColumns = [];
        foreach ($tables as $table) {
            if (($engines[$table] ?? null) !== 'InnoDB') {
                throw new RuntimeException("Initialization blocked: required InnoDB table is missing or uses another engine: {$table}.");
            }
            $columns = array_map(
                fn (object $column): string => $column->column_name,
                $connection->select(
                    'SELECT COLUMN_NAME AS column_name FROM information_schema.COLUMNS '
                        .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                    [$table],
                ),
            );
            $tableColumns[$table] = $columns;
            foreach (self::REQUIRED_COLUMNS[$table] as $required) {
                if (! in_array($required, $columns, true)) {
                    throw new RuntimeException("Initialization blocked: {$table}.{$required} is missing.");
                }
            }
        }

        foreach ([
            ['ppks', ['code']],
            ['weighbridge_centres', ['code']],
            ['ffb_source_aliases', ['normalized_code']],
            ['ffb_source_groups', ['normalized_heading']],
            ['ffb_source_code_mappings', ['ffb_source_group_id', 'normalized_code']],
        ] as [$table, $columns]) {
            if (! $this->hasUniqueIndex($connection, $table, $columns)) {
                throw new RuntimeException("Initialization blocked: required unique index is missing on {$table}.");
            }
        }

        foreach (self::REQUIRED_FOREIGN_KEYS as $table => $columns) {
            foreach ($columns as $column) {
                $exists = $connection->selectOne(
                    'SELECT 1 AS present FROM information_schema.KEY_COLUMN_USAGE '
                        .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? '
                        .'AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1',
                    [$table, $column],
                );
                if (! $exists) {
                    throw new RuntimeException("Initialization blocked: required foreign key is missing for {$table}.{$column}.");
                }
            }
        }

        $triggers = $connection->select(
            'SELECT TRIGGER_NAME AS trigger_name, ACTION_STATEMENT AS action_statement, '
                .'EVENT_OBJECT_TABLE AS event_object_table, EVENT_MANIPULATION AS event_manipulation, '
                .'ACTION_TIMING AS action_timing '
                .'FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
        );
        (new FfbStage3TriggerVerifier())->assertValid(
            $triggers,
            $tableColumns['ffb_import_batches'],
            $tableColumns['daily_operations'],
        );
    }

    private function hasUniqueIndex(Connection $connection, string $table, array $columns): bool
    {
        $indexes = $connection->select(
            'SELECT INDEX_NAME AS index_name, COLUMN_NAME AS column_name, SEQ_IN_INDEX AS sequence_number '
                .'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
                .'AND NON_UNIQUE = 0 ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table],
        );
        $grouped = [];
        foreach ($indexes as $index) {
            $grouped[$index->index_name][(int) $index->sequence_number - 1] = $index->column_name;
        }

        foreach ($grouped as $indexedColumns) {
            ksort($indexedColumns);
            if (array_values($indexedColumns) === $columns) {
                return true;
            }
        }

        return false;
    }

    private function masterCounts(Connection $connection): array
    {
        $counts = [];
        foreach (self::MASTER_TABLES as $table) {
            $counts[$table] = (int) $connection->table($table)->count();
        }

        return $counts;
    }

    private function masterPlan(Connection $connection, bool $lock = false): array
    {
        $rows = [];
        foreach (self::MASTER_TABLES as $table) {
            $query = $connection->table($table)->orderBy('id');
            if ($lock) {
                $query->lockForUpdate();
            }
            $rows[$table] = $query->get()->all();
        }

        $plan = (new FfbApprovedMasterPlan())->compare($rows);
        if ($plan['expected_final_counts'] !== self::MAX_MASTER_COUNTS) {
            throw new RuntimeException('Approved master specification has unexpected counts.');
        }

        return $plan;
    }

    private function assertMasterCountsUnchanged(array $expected): void
    {
        if ($this->masterCounts(DB::connection()) !== $expected) {
            throw new RuntimeException('Master counts changed after preflight; initialization aborted.');
        }
    }

    private function assertFinalMasterCounts(Connection $connection): void
    {
        $expected = [
            'ppks' => 30,
            'weighbridge_centres' => 31,
            'ffb_source_aliases' => 0,
            'ffb_source_groups' => 17,
            'ffb_source_code_mappings' => 24,
        ];
        if ($this->masterCounts($connection) !== $expected
            || Ppk::official()->count() !== 29
            || Ppk::where('entity_type', Ppk::INDEPENDENT_INTEGRATED_ESTATE)->count() !== 1
            || WeighbridgeCentre::where('source_category', 'ppnj_owned')->count() < 1
            || WeighbridgeCentre::where('source_category', 'ppnj_ppk_integrated')->count() < 1
            || WeighbridgeCentre::where('source_category', 'external_supplier')->count() < 1) {
            throw new RuntimeException('Initialized masters do not match approved record counts or the three approved categories.');
        }
    }

    private function operationalSnapshot(Connection $connection): array
    {
        $snapshot = [];
        foreach (self::OPERATIONAL_TABLES as $table) {
            $rows = $connection->table($table)->orderBy('id')->get()->map(
                fn (object $row): array => (array) $row,
            )->all();
            $snapshot[$table] = [
                'count' => count($rows),
                'sha256' => hash('sha256', json_encode(
                    $rows,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                )),
            ];
        }

        return $snapshot;
    }
}
