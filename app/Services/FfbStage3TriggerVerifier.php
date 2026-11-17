<?php

namespace App\Services;

use RuntimeException;

final class FfbStage3TriggerVerifier
{
    /**
     * Tokenized ACTION_STATEMENT signatures from the Stage 3 trigger migrations.
     * Column-dependent immutability expressions are replaced only after their
     * complete expressions have been checked against the current schema.
     */
    private const DEFINITIONS = [
        'ffb_batches_locked_update' => ['ffb_import_batches', 'UPDATE', '58e942d1c5bc266e34fa7e83ffb53a00c556e9e1d47c9d5bf9dbb61cb7be9c01'],
        'ffb_batches_locked_delete' => ['ffb_import_batches', 'DELETE', 'a7eb1c1e6025708526c6320aa804841ede481ac4065e607995b18a33b7d63ced'],
        'ffb_tickets_locked_insert' => ['ffb_weighbridge_tickets', 'INSERT', '5e1ad949a2843f0764ba4f3f45f823c51460d272bb2a75ad1e82575f86e2b62e'],
        'ffb_tickets_locked_update' => ['ffb_weighbridge_tickets', 'UPDATE', 'db504e9d4af21e09f09002ef411ccdbc9223f9a5731863e53b0a084f09a5c2ec'],
        'ffb_tickets_locked_delete' => ['ffb_weighbridge_tickets', 'DELETE', 'e5d060a617cbe9cb5ead38958fe426466d851a95470540856aec017e182075c8'],
        'daily_ffb_insert' => ['daily_operations', 'INSERT', '1e51b80c4e8981b30f310ff9de252ed25275e8a2cd09641067b1ac1dbd3f65b8'],
        'daily_ffb_update' => ['daily_operations', 'UPDATE', '1b846d7e1db74afaa2abc4a2908b72df4720062dc0b60faec2d47b82df25857b'],
        'daily_ffb_delete' => ['daily_operations', 'DELETE', '1d493d455df0a655998a815d9254a5c0a43071db5afc9f21009a0c763ff47ac2'],
        'ffb_audit_locked_update' => ['audit_logs', 'UPDATE', 'f7096d681720c491056f8b590d0d81641f46e79ec18c75fd6afe9cffb97b3c2c'],
        'ffb_audit_locked_delete' => ['audit_logs', 'DELETE', 'd9360bc87041d843b44ba955d1a344de9d0e778552418ed8f9b5a708d0b9354c'],
    ];

    /**
     * @param  array<int, object>  $triggers
     * @param  array<int, string>  $batchColumns
     * @param  array<int, string>  $dailyColumns
     */
    public function assertValid(array $triggers, array $batchColumns, array $dailyColumns): void
    {
        $byName = [];
        foreach ($triggers as $trigger) {
            $byName[$trigger->trigger_name] = $trigger;
        }

        foreach (self::DEFINITIONS as $name => [$table, $event, $expectedHash]) {
            $trigger = $byName[$name] ?? null;
            if ($trigger === null || $trigger->event_object_table !== $table
                || $trigger->event_manipulation !== $event || $trigger->action_timing !== 'BEFORE') {
                throw new RuntimeException("Initialization blocked: required operational trigger is missing or invalid: {$name}.");
            }

            $body = $this->tokenize($trigger->action_statement);
            if ($name === 'ffb_batches_locked_update') {
                $body = $this->replaceImmutabilityCheck($body, $batchColumns, [
                    'status', 'active_slot', 'daily_operation_id', 'synced_at', 'updated_at',
                ], 'FFB_BATCH_CONTENT_CHECK', $name);
            } elseif ($name === 'daily_ffb_update') {
                $body = $this->replaceImmutabilityCheck($body, $dailyColumns, [
                    'ffb_import_batch_id', 'bts_diterima', 'baki_bts_semalam',
                    'baki_bts_selepas_diproses', 'last_amendment_reason',
                    'last_amended_by', 'last_amended_at', 'updated_at',
                ], 'FFB_DAILY_CONTENT_CHECK', $name);
            }

            if (! hash_equals($expectedHash, hash('sha256', $body))) {
                throw new RuntimeException("Initialization blocked: required operational trigger is missing or invalid: {$name}.");
            }
        }
    }

    private function replaceImmutabilityCheck(
        string $body,
        array $columns,
        array $allowed,
        string $placeholder,
        string $name,
    ): string {
        $protected = array_diff($columns, $allowed);
        if ($protected === []) {
            throw new RuntimeException("Initialization blocked: cannot verify protected columns for {$name}.");
        }
        foreach ($protected as $column) {
            if (! preg_match('/^[A-Za-z0-9_]+$/D', $column)) {
                throw new RuntimeException("Initialization blocked: invalid schema column for {$name}.");
            }
        }
        $expression = implode(' OR ', array_map(
            fn (string $column): string => "NOT (OLD.`{$column}` <=> NEW.`{$column}`)",
            $protected,
        ));
        $tokens = $this->tokenize($expression);
        if (substr_count($body, $tokens) !== 2) {
            throw new RuntimeException("Initialization blocked: incomplete immutable evidence guard: {$name}.");
        }

        return str_replace($tokens, $placeholder, $body);
    }

    private function tokenize(string $sql): string
    {
        $pattern = "/\\s+|'(?:\\\\\\\\.|''|[^'\\\\\\\\])*'|`[^`]*`|[a-zA-Z_][a-zA-Z0-9_\\$]*|[0-9]+(?:\\.[0-9]+)?|<=>|<>|!=|<=|>=|:=|[=(),.;+*\\/< >-]/";
        preg_match_all($pattern, $sql, $matches, PREG_OFFSET_CAPTURE);
        $offset = 0;
        $tokens = [];
        foreach ($matches[0] as [$token, $position]) {
            if ($position !== $offset) {
                throw new RuntimeException('Initialization blocked: unsupported operational trigger definition.');
            }
            $offset += strlen($token);
            if (! ctype_space($token)) {
                $tokens[] = $token;
            }
        }
        if ($offset !== strlen($sql) || $tokens === []) {
            throw new RuntimeException('Initialization blocked: unsupported operational trigger definition.');
        }

        return implode("\x1f", $tokens);
    }
}
