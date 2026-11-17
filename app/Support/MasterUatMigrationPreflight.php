<?php

namespace App\Support;

use RuntimeException;

final class MasterUatMigrationPreflight
{
    public const RECOVERY_MIGRATION = '2026_11_03_000005_lock_confirmed_ffb_receipts';

    private const RECOVERY_PREFIX_LENGTH = 31;

    private const EXPECTED_OPERATION_STATUS_TYPE = "enum('Operasi','Tidak Operasi (Terima Buah Sahaja)','Tidak Operasi (Tutup Sepenuhnya)')";

    private const REQUIRED_RECOVERY_TABLES = [
        'migrations',
        'daily_operations',
        'ppks',
        'ffb_import_batches',
        'ffb_weighbridge_tickets',
        'audit_logs',
    ];

    public static function assertMigrationHistory(array $canonicalMigrations, array $records): string
    {
        if ($canonicalMigrations === [] || count(array_unique($canonicalMigrations)) !== count($canonicalMigrations)) {
            throw new RuntimeException('Master UAT migration files are missing or contain duplicate names.');
        }
        $sortedMigrations = $canonicalMigrations;
        sort($sortedMigrations, SORT_STRING);
        if ($sortedMigrations !== $canonicalMigrations) {
            throw new RuntimeException('Master UAT migration files are not in canonical filename order.');
        }

        if ($records === []) {
            return 'empty';
        }

        $names = [];
        $previousId = 0;
        $previousBatch = 0;

        foreach ($records as $record) {
            $id = filter_var($record['id'] ?? null, FILTER_VALIDATE_INT);
            $batch = filter_var($record['batch'] ?? null, FILTER_VALIDATE_INT);
            $name = $record['migration'] ?? null;

            if ($id === false || $id < 1 || $id <= $previousId) {
                throw new RuntimeException('Master UAT migration history has invalid or reordered record IDs.');
            }
            if ($batch === false || $batch < 1 || ($previousBatch === 0 && $batch !== 1)
                || ($previousBatch !== 0 && $batch !== $previousBatch && $batch !== $previousBatch + 1)) {
                throw new RuntimeException('Master UAT migration history has invalid or non-contiguous batch metadata.');
            }
            if (! is_string($name) || ! in_array($name, $canonicalMigrations, true)) {
                throw new RuntimeException('Master UAT migration history contains an unknown migration.');
            }
            if (in_array($name, $names, true)) {
                throw new RuntimeException("Master UAT migration history contains duplicate migration {$name}.");
            }

            $names[] = $name;
            $previousId = $id;
            $previousBatch = $batch;
        }

        if (array_slice($canonicalMigrations, 0, count($names)) !== $names) {
            throw new RuntimeException('Master UAT migration history is missing, reordered, or not a canonical prefix.');
        }

        if (count($names) === count($canonicalMigrations)) {
            return 'complete';
        }

        if (count($names) === self::RECOVERY_PREFIX_LENGTH
            && ($canonicalMigrations[self::RECOVERY_PREFIX_LENGTH] ?? null) === self::RECOVERY_MIGRATION) {
            return 'recovery';
        }

        throw new RuntimeException('Master UAT migration history is partial outside the approved 31-migration recovery state.');
    }

    public static function assertRecoverySchema(array $schema): void
    {
        $tables = $schema['tables'] ?? [];
        foreach (self::REQUIRED_RECOVERY_TABLES as $table) {
            if (! in_array($table, $tables, true)) {
                throw new RuntimeException("Master UAT recovery preflight requires table {$table}.");
            }
        }

        if (in_array('mill_monthly_histories', $tables, true)) {
            throw new RuntimeException('Master UAT recovery preflight found the pending mill_monthly_histories table.');
        }

        if (in_array('entity_type', $schema['ppk_columns'] ?? [], true)) {
            throw new RuntimeException('Master UAT recovery preflight found the pending ppks.entity_type column.');
        }

        $operationStatus = $schema['operation_status'] ?? [];
        if (($operationStatus['data_type'] ?? null) !== 'enum'
            || ($operationStatus['column_type'] ?? null) !== self::EXPECTED_OPERATION_STATUS_TYPE
            || strtoupper((string) ($operationStatus['is_nullable'] ?? '')) !== 'NO'
            || ($operationStatus['column_default'] ?? null) !== 'Operasi') {
            throw new RuntimeException('Master UAT recovery preflight found an unexpected daily_operations.operation_status definition.');
        }

        $presentTriggers = $schema['triggers'] ?? [];
        if ($presentTriggers !== []) {
            throw new RuntimeException(
                'Master UAT recovery preflight requires no triggers; found: '.implode(', ', $presentTriggers).'.'
            );
        }
    }
}
