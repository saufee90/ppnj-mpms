<?php

namespace Tests\Unit\Support;

use App\Support\MasterUatMigrationPreflight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MasterUatMigrationPreflightTest extends TestCase
{
    public function test_exact_recovery_prefix_is_accepted(): void
    {
        $canonical = $this->canonicalMigrations();
        $this->assertSame(
            'recovery',
            MasterUatMigrationPreflight::assertMigrationHistory($canonical, $this->records($canonical, 31)),
        );
    }

    public function test_complete_migration_history_is_accepted(): void
    {
        $canonical = $this->canonicalMigrations();
        $this->assertSame(
            'complete',
            MasterUatMigrationPreflight::assertMigrationHistory($canonical, $this->records($canonical, count($canonical))),
        );
    }

    public function test_valid_contiguous_batch_metadata_is_accepted(): void
    {
        $canonical = $this->canonicalMigrations();
        $records = $this->records($canonical, 31);
        foreach ($records as $index => &$record) {
            $record['batch'] = $index < 12 ? 1 : 2;
        }
        unset($record);

        $this->assertSame('recovery', MasterUatMigrationPreflight::assertMigrationHistory($canonical, $records));
    }

    public function test_empty_history_is_identified_for_the_caller_to_restrict_to_an_empty_schema(): void
    {
        $this->assertSame('empty', MasterUatMigrationPreflight::assertMigrationHistory($this->canonicalMigrations(), []));
    }

    public static function invalidHistories(): array
    {
        return [
            'short contiguous prefix' => ['short'],
            'missing migration' => ['missing'],
            'duplicate migration' => ['duplicate'],
            'unknown migration' => ['unknown'],
            'reordered migration' => ['reordered'],
            'reordered record ids' => ['reordered_id'],
            'duplicate record id' => ['duplicate_id'],
            'invalid batch' => ['invalid_batch'],
            'skipped batch' => ['skipped_batch'],
            'decreasing batch' => ['decreasing_batch'],
            'history beyond recovery prefix' => ['post_recovery_partial'],
            'history missing final migration' => ['missing_final_migration'],
        ];
    }

    #[DataProvider('invalidHistories')]
    public function test_invalid_migration_histories_are_rejected(string $case): void
    {
        $canonical = $this->canonicalMigrations();
        $records = $this->records($canonical, 31);

        switch ($case) {
            case 'short':
                array_pop($records);
                break;
            case 'post_recovery_partial':
                $records = $this->records($canonical, 32);
                break;
            case 'missing_final_migration':
                $records = $this->records($canonical, count($canonical) - 1);
                break;
            case 'missing':
                array_splice($records, 10, 1);
                break;
            case 'duplicate':
                $records[10]['migration'] = $records[9]['migration'];
                break;
            case 'unknown':
                $records[10]['migration'] = 'unknown_migration';
                break;
            case 'reordered':
                [$records[10]['migration'], $records[11]['migration']] = [$records[11]['migration'], $records[10]['migration']];
                break;
            case 'reordered_id':
                [$records[10]['id'], $records[11]['id']] = [$records[11]['id'], $records[10]['id']];
                break;
            case 'duplicate_id':
                $records[10]['id'] = $records[9]['id'];
                break;
            case 'invalid_batch':
                $records[10]['batch'] = 0;
                break;
            case 'skipped_batch':
                $records[10]['batch'] = 3;
                break;
            case 'decreasing_batch':
                $records[10]['batch'] = 1;
                $records[11]['batch'] = 2;
                $records[12]['batch'] = 1;
                break;
        }

        $this->expectException(RuntimeException::class);
        MasterUatMigrationPreflight::assertMigrationHistory($canonical, $records);
    }

    public function test_duplicate_canonical_migration_files_are_rejected(): void
    {
        $canonical = $this->canonicalMigrations();
        $canonical[1] = $canonical[0];

        $this->expectException(RuntimeException::class);
        MasterUatMigrationPreflight::assertMigrationHistory($canonical, $this->records($canonical, 31));
    }

    public function test_noncanonical_migration_file_order_is_rejected(): void
    {
        $canonical = $this->canonicalMigrations();
        [$canonical[0], $canonical[1]] = [$canonical[1], $canonical[0]];

        $this->expectException(RuntimeException::class);
        MasterUatMigrationPreflight::assertMigrationHistory($canonical, []);
    }

    public function test_expected_recovery_schema_is_accepted(): void
    {
        MasterUatMigrationPreflight::assertRecoverySchema($this->recoverySchema());
        $this->addToAssertionCount(1);
    }

    public static function invalidEnumDefinitions(): array
    {
        return [
            'wrong enum values' => ['column_type', "enum('Operasi','Tidak Operasi')"],
            'wrong data type' => ['data_type', 'varchar'],
            'nullable' => ['is_nullable', 'YES'],
            'wrong default' => ['column_default', 'Tidak Operasi'],
        ];
    }

    #[DataProvider('invalidEnumDefinitions')]
    public function test_unexpected_operation_status_definitions_are_rejected(string $field, string $value): void
    {
        $schema = $this->recoverySchema();
        $schema['operation_status'][$field] = $value;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operation_status definition');
        MasterUatMigrationPreflight::assertRecoverySchema($schema);
    }

    public function test_missing_operation_status_column_is_rejected(): void
    {
        $schema = $this->recoverySchema();
        $schema['operation_status'] = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operation_status definition');
        MasterUatMigrationPreflight::assertRecoverySchema($schema);
    }

    public static function residualSchemaArtifacts(): array
    {
        $triggers = [
            'ffb_batches_locked_update',
            'ffb_batches_locked_delete',
            'ffb_tickets_locked_insert',
            'ffb_tickets_locked_update',
            'ffb_tickets_locked_delete',
            'daily_ffb_insert',
            'daily_ffb_update',
            'daily_ffb_delete',
            'ffb_audit_locked_update',
            'ffb_audit_locked_delete',
            'ffb_parallel_run_link_guard_insert',
            'ffb_parallel_run_link_guard_update',
            'ffb_orphan_guard_insert',
            'ffb_orphan_guard_update',
            'daily_confirmed_batch_guard_insert',
            'daily_confirmed_batch_guard_update',
        ];
        $cases = [];
        foreach ($triggers as $trigger) {
            $cases['pending trigger '.$trigger] = ['trigger', $trigger];
        }

        return $cases + [
            'pending table' => ['table', 'mill_monthly_histories'],
            'pending column' => ['column', 'entity_type'],
            'missing base table' => ['missing_table', 'ffb_import_batches'],
            'unexpected trigger' => ['trigger', 'unexpected_trigger'],
        ];
    }

    #[DataProvider('residualSchemaArtifacts')]
    public function test_pending_or_unexpected_recovery_schema_artifacts_are_rejected(string $type, string $name): void
    {
        $schema = $this->recoverySchema();
        if ($type === 'trigger') {
            $schema['triggers'][] = $name;
        } elseif ($type === 'table') {
            $schema['tables'][] = $name;
        } elseif ($type === 'column') {
            $schema['ppk_columns'][] = $name;
        } else {
            $schema['tables'] = array_values(array_diff($schema['tables'], [$name]));
        }

        $this->expectException(RuntimeException::class);
        MasterUatMigrationPreflight::assertRecoverySchema($schema);
    }

    private function canonicalMigrations(): array
    {
        $files = glob(dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($files, SORT_STRING);

        return array_map(fn (string $file): string => pathinfo($file, PATHINFO_FILENAME), $files);
    }

    private function records(array $canonical, int $count): array
    {
        $records = [];
        foreach (array_slice($canonical, 0, $count) as $index => $migration) {
            $records[] = ['id' => $index + 1, 'migration' => $migration, 'batch' => 1];
        }

        return $records;
    }

    private function recoverySchema(): array
    {
        return [
            'tables' => [
                'migrations',
                'daily_operations',
                'ppks',
                'ffb_import_batches',
                'ffb_weighbridge_tickets',
                'audit_logs',
            ],
            'ppk_columns' => ['id', 'name'],
            'operation_status' => [
                'data_type' => 'enum',
                'column_type' => "enum('Operasi','Tidak Operasi (Terima Buah Sahaja)','Tidak Operasi (Tutup Sepenuhnya)')",
                'is_nullable' => 'NO',
                'column_default' => 'Operasi',
            ],
            'triggers' => [],
        ];
    }
}
