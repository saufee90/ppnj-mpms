<?php

namespace Tests\Unit\Services;

use App\Services\FfbApprovedMasterInitializer;
use App\Services\FfbStage3TriggerVerifier;
use Illuminate\Database\Connection;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Stage3TriggerDefinitions;

class FfbStage3TriggerVerifierTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_migration_defined_stage3_triggers_are_accepted(): void
    {
        $triggers = Stage3TriggerDefinitions::triggers();
        $this->assertCount(10, $triggers);
        $batch = $triggers['ffb_batches_locked_update']->action_statement;
        $this->assertStringContainsString('Confirmed FFB receipt is locked', $batch);
        $this->assertStringNotContainsString('Confirmed FFB receipt is permanently locked', $batch);
        $this->assertStringContainsString("OLD.status='confirmed' AND NEW.status='superseded'", $batch);
        $this->assertStringContainsString('Valid replacement batch and correction reason are required', $batch);
        $this->assertStringContainsString('Superseded FFB history is immutable', $batch);

        foreach ($triggers as $trigger) {
            $trigger->action_statement = "\r\n ".$trigger->action_statement."\n";
        }
        $this->verify($triggers);
        $this->addToAssertionCount(1);
    }

    public static function triggerNames(): array
    {
        return array_map(
            fn (string $name): array => [$name],
            [
                'ffb_batches_locked_update', 'ffb_batches_locked_delete',
                'ffb_tickets_locked_insert', 'ffb_tickets_locked_update', 'ffb_tickets_locked_delete',
                'daily_ffb_insert', 'daily_ffb_update', 'daily_ffb_delete',
                'ffb_audit_locked_update', 'ffb_audit_locked_delete',
            ],
        );
    }

    #[DataProvider('triggerNames')]
    public function test_every_required_trigger_must_be_present(string $name): void
    {
        $triggers = Stage3TriggerDefinitions::triggers();
        unset($triggers[$name]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($name);
        $this->verify($triggers);
    }

    #[DataProvider('triggerNames')]
    public function test_every_trigger_rejects_weakened_signal_even_when_the_message_remains(string $name): void
    {
        $triggers = Stage3TriggerDefinitions::triggers();
        $body = $triggers[$name]->action_statement;
        $this->assertStringContainsString("SIGNAL SQLSTATE '45000'", $body);
        $triggers[$name]->action_statement = str_replace("SIGNAL SQLSTATE '45000'", "SIGNAL SQLSTATE '01000'", $body);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($name);
        $this->verify($triggers);
    }

    public static function weakenedCorrectionGuards(): array
    {
        return [
            'confirmed evidence' => ['ffb_batches_locked_update', 'NOT (OLD.`confirmation_snapshot` <=> NEW.`confirmation_snapshot`)', 'FALSE'],
            'controlled supersession' => ['ffb_batches_locked_update', "OLD.status='confirmed' AND NEW.status='superseded'", "NEW.status='superseded'"],
            'replacement predecessor' => ['ffb_batches_locked_update', 'c.supersedes_batch_id=OLD.id', '1=1'],
            'replacement readiness' => ['ffb_batches_locked_update', "c.status='ready'", '1=1'],
            'replacement mill' => ['ffb_batches_locked_update', 'c.mill_id=OLD.mill_id', '1=1'],
            'replacement date' => ['ffb_batches_locked_update', 'c.receipt_date=OLD.receipt_date', '1=1'],
            'replacement reason' => ['ffb_batches_locked_update', 'c.confirmation_reason IS NOT NULL', '1=1'],
            'nonblank correction reason' => ['ffb_batches_locked_update', "TRIM(c.confirmation_reason)<>''", '1=1'],
            'replacement validation' => ['ffb_batches_locked_update', 'c.validated_at IS NOT NULL', '1=1'],
            'replacement preview hash' => ['ffb_batches_locked_update', 'c.preview_hash IS NOT NULL', '1=1'],
            'correction date' => ['ffb_batches_locked_update', "OLD.receipt_date < '2026-11-05'", 'FALSE'],
            'daily link evidence' => ['ffb_batches_locked_update', 'd.ffb_import_batch_id=OLD.id', '1=1'],
            'superseded history' => ['ffb_batches_locked_update', "OLD.status='superseded'", "OLD.status='nonexistent'"],
            'daily correction predecessor' => ['daily_ffb_update', "old_batch.status='superseded'", '1=1'],
            'daily correction replacement' => ['daily_ffb_update', "new_batch.status='confirmed'", '1=1'],
            'daily historical link' => ['daily_ffb_update', 'OLD.ffb_import_batch_id IS NULL AND NEW.ffb_import_batch_id IS NOT NULL', 'FALSE'],
        ];
    }

    #[DataProvider('weakenedCorrectionGuards')]
    public function test_weakened_correction_logic_is_rejected(string $name, string $search, string $replacement): void
    {
        $triggers = Stage3TriggerDefinitions::triggers();
        $body = $triggers[$name]->action_statement;
        $this->assertStringContainsString($search, $body);
        $triggers[$name]->action_statement = str_replace($search, $replacement, $body);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($name);
        $this->verify($triggers);
    }

    #[DataProvider('triggerNames')]
    public function test_wrong_trigger_metadata_is_rejected(string $name): void
    {
        $triggers = Stage3TriggerDefinitions::triggers();
        $triggers[$name]->action_timing = 'AFTER';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($name);
        $this->verify($triggers);
    }

    public function test_new_unprotected_schema_column_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ffb_batches_locked_update');
        (new FfbStage3TriggerVerifier())->assertValid(
            array_values(Stage3TriggerDefinitions::triggers()),
            [...Stage3TriggerDefinitions::BATCH_COLUMNS, 'new_evidence'],
            Stage3TriggerDefinitions::DAILY_COLUMNS,
        );
    }

    public function test_wrong_trigger_table_or_event_is_rejected(): void
    {
        foreach (['event_object_table' => 'daily_operations', 'event_manipulation' => 'INSERT'] as $field => $value) {
            $triggers = Stage3TriggerDefinitions::triggers();
            $triggers['ffb_batches_locked_update']->{$field} = $value;
            try {
                $this->verify($triggers);
                $this->fail("Incorrect trigger {$field} was accepted.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('ffb_batches_locked_update', $exception->getMessage());
            }
        }
    }

    public function test_new_unprotected_daily_column_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('daily_ffb_update');
        (new FfbStage3TriggerVerifier())->assertValid(
            array_values(Stage3TriggerDefinitions::triggers()),
            Stage3TriggerDefinitions::BATCH_COLUMNS,
            [...Stage3TriggerDefinitions::DAILY_COLUMNS, 'new_daily_evidence'],
        );
    }

    public function test_initializer_schema_preflight_uses_trigger_metadata_and_accepts_stage3_definitions(): void
    {
        $triggers = Stage3TriggerDefinitions::triggers();
        $this->invokeSchemaPreflight($triggers);
        $this->addToAssertionCount(1);
    }

    public function test_initializer_schema_preflight_rejects_weakened_trigger(): void
    {
        $triggers = Stage3TriggerDefinitions::triggers();
        $triggers['ffb_batches_locked_update']->action_statement = str_replace(
            'c.preview_hash IS NOT NULL',
            '1=1',
            $triggers['ffb_batches_locked_update']->action_statement,
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ffb_batches_locked_update');
        $this->invokeSchemaPreflight($triggers);
    }

    private function invokeSchemaPreflight(array $triggers): void
    {
        $initializer = new FfbApprovedMasterInitializer();
        $class = new \ReflectionClass($initializer);
        $columns = $class->getReflectionConstant('REQUIRED_COLUMNS')->getValue();
        $columns['ffb_import_batches'] = Stage3TriggerDefinitions::BATCH_COLUMNS;
        $columns['daily_operations'] = Stage3TriggerDefinitions::DAILY_COLUMNS;
        $unique = [
            'ppks' => ['code'],
            'weighbridge_centres' => ['code'],
            'ffb_source_aliases' => ['normalized_code'],
            'ffb_source_groups' => ['normalized_heading'],
            'ffb_source_code_mappings' => ['ffb_source_group_id', 'normalized_code'],
        ];
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('select')->andReturnUsing(
            function (string $sql, array $bindings = []) use ($columns, $unique, $triggers): array {
                if (str_contains($sql, 'information_schema.TABLES')) {
                    return array_map(
                        fn (string $table): object => (object) ['table_name' => $table, 'engine' => 'InnoDB'],
                        $bindings,
                    );
                }
                if (str_contains($sql, 'information_schema.COLUMNS')) {
                    $this->assertStringContainsString('ORDER BY ORDINAL_POSITION', $sql);

                    return array_map(fn (string $column): object => (object) ['column_name' => $column], $columns[$bindings[0]]);
                }
                if (str_contains($sql, 'information_schema.STATISTICS')) {
                    $result = [];
                    foreach ($unique[$bindings[0]] as $index => $column) {
                        $result[] = (object) ['index_name' => 'unique_test', 'column_name' => $column, 'sequence_number' => $index + 1];
                    }

                    return $result;
                }
                if (str_contains($sql, 'information_schema.TRIGGERS')) {
                    foreach (['EVENT_OBJECT_TABLE', 'EVENT_MANIPULATION', 'ACTION_TIMING'] as $field) {
                        $this->assertStringContainsString($field, $sql);
                    }

                    return array_values($triggers);
                }
                $this->fail('Unexpected schema query: '.$sql);
            },
        );
        $connection->shouldReceive('selectOne')->andReturn((object) ['present' => 1]);
        $class->getMethod('assertSchema')->invoke($initializer, $connection);
    }

    private function verify(array $triggers): void
    {
        (new FfbStage3TriggerVerifier())->assertValid(
            array_values($triggers),
            Stage3TriggerDefinitions::BATCH_COLUMNS,
            Stage3TriggerDefinitions::DAILY_COLUMNS,
        );
    }
}
