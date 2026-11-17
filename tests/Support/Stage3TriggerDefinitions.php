<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Reconstructs trigger SQL from migration source as text; migrations are never executed.
 */
final class Stage3TriggerDefinitions
{
    public const BATCH_COLUMNS = [
        'id', 'mill_id', 'receipt_date', 'status', 'receipt_type', 'confirmation_snapshot',
        'preview_hash', 'supersedes_batch_id', 'active_slot', 'daily_operation_id', 'synced_at', 'updated_at',
    ];

    public const DAILY_COLUMNS = [
        'id', 'mill_id', 'tarikh', 'shift', 'ffb_import_batch_id', 'bts_diterima',
        'baki_bts_semalam', 'baki_bts_selepas_diproses', 'last_amendment_reason',
        'last_amended_by', 'last_amended_at', 'updated_at',
    ];

    public static function triggers(): array
    {
        $directory = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR;
        $initial = file_get_contents($directory.'2026_11_03_000005_lock_confirmed_ffb_receipts.php');
        $correction = file_get_contents($directory.'2026_11_05_000002_enable_audited_ffb_corrections.php');
        if ($initial === false || $correction === false) {
            throw new RuntimeException('Cannot read trigger migration source.');
        }

        $triggers = [];
        foreach ([
            'ffb_batches_locked_update' => ['ffb_import_batches', 'UPDATE'],
            'daily_ffb_update' => ['daily_operations', 'UPDATE'],
        ] as $name => [$table, $event]) {
            $body = self::extract(
                '/CREATE TRIGGER '.preg_quote($name, '/').' BEFORE .*?FOR EACH ROW\s*(BEGIN.*?\n\s*END)\s*\n\s*SQL\);/s',
                $correction,
            );
            $body = str_replace(
                ['{$batchContentChanged}', '{$dailyContentChanged}'],
                [
                    self::changed(self::BATCH_COLUMNS, ['status', 'active_slot', 'daily_operation_id', 'synced_at', 'updated_at']),
                    self::changed(self::DAILY_COLUMNS, [
                        'ffb_import_batch_id', 'bts_diterima', 'baki_bts_semalam', 'baki_bts_selepas_diproses',
                        'last_amendment_reason', 'last_amended_by', 'last_amended_at', 'updated_at',
                    ]),
                ],
                $body,
            );
            $triggers[$name] = self::trigger($name, $table, $event, $body);
        }

        foreach ([
            'ffb_batches_locked_delete' => ['ffb_import_batches', 'DELETE'],
            'daily_ffb_delete' => ['daily_operations', 'DELETE'],
        ] as $name => [$table, $event]) {
            $body = self::extract(
                '/CREATE TRIGGER '.preg_quote($name, '/').' BEFORE .*?FOR EACH ROW\s*(BEGIN.*?\n\s*END)"\);/s',
                $initial,
            );
            $triggers[$name] = self::trigger($name, $table, $event, $body);
        }

        $template = self::extract('/CREATE TRIGGER \{\$name\} BEFORE .*?FOR EACH ROW\s*(BEGIN.*?\n\s*END)"\);/s', $initial);
        foreach (['INSERT' => ['NEW'], 'UPDATE' => ['OLD', 'NEW'], 'DELETE' => ['OLD']] as $event => $rows) {
            $condition = implode(' OR ', array_map(
                fn (string $row): string => "EXISTS (SELECT 1 FROM ffb_import_batches b WHERE b.id={$row}.ffb_import_batch_id AND (b.status IN ('confirmed','superseded') OR b.receipt_type='zero_receipt'))",
                $rows,
            ));
            $name = 'ffb_tickets_locked_'.strtolower($event);
            $triggers[$name] = self::trigger($name, 'ffb_weighbridge_tickets', $event, str_replace('{$condition}', $condition, $template));
        }

        $template = self::extract('/CREATE TRIGGER ffb_audit_locked_.*?FOR EACH ROW\s*(BEGIN.*?\n\s*END)"\);/s', $initial);
        foreach (['UPDATE', 'DELETE'] as $event) {
            $condition = "LEFT(OLD.action,4)='ffb_'".($event === 'UPDATE' ? " OR LEFT(NEW.action,4)='ffb_'" : '');
            $name = 'ffb_audit_locked_'.strtolower($event);
            $triggers[$name] = self::trigger($name, 'audit_logs', $event, str_replace('{$condition}', $condition, $template));
        }

        $valid = self::extract('/\$valid = "(.*?)";/s', $initial);
        $immutable = self::extract('/END IF;" : "(.*?)";/s', $initial);
        $template = self::extract('/CREATE TRIGGER daily_ffb_.*?FOR EACH ROW\s*(BEGIN.*?\n\s*END)"\);/s', $initial);
        $body = str_replace(['{$immutable}', '{$valid}'], [$immutable, $valid], $template);
        $triggers['daily_ffb_insert'] = self::trigger('daily_ffb_insert', 'daily_operations', 'INSERT', $body);

        return $triggers;
    }

    private static function extract(string $pattern, string $source): string
    {
        if (preg_match($pattern, $source, $match) !== 1) {
            throw new RuntimeException("Trigger migration template not found: {$pattern}");
        }

        return $match[1];
    }

    private static function changed(array $columns, array $allowed): string
    {
        return implode(' OR ', array_map(
            fn (string $column): string => "NOT (OLD.`{$column}` <=> NEW.`{$column}`)",
            array_diff($columns, $allowed),
        ));
    }

    private static function trigger(string $name, string $table, string $event, string $body): object
    {
        return (object) [
            'trigger_name' => $name, 'event_object_table' => $table,
            'event_manipulation' => $event, 'action_timing' => 'BEFORE',
            'action_statement' => $body,
        ];
    }
}
