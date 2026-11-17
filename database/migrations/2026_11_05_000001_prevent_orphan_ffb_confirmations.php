<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TRIGGERS = [
        'ffb_orphan_guard_insert',
        'ffb_orphan_guard_update',
        'ffb_parallel_run_link_guard_insert',
        'ffb_parallel_run_link_guard_update',
        'daily_confirmed_batch_guard_insert',
        'daily_confirmed_batch_guard_update',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('FFB orphan guards require MySQL.');
        }

        DB::unprepared("CREATE TRIGGER ffb_parallel_run_link_guard_insert BEFORE INSERT ON ffb_import_batches
            FOR EACH ROW
            BEGIN
                IF NEW.receipt_date BETWEEN '2026-11-05' AND '2026-11-30'
                    AND NEW.daily_operation_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Parallel Run FFB batches cannot be linked to daily operations';
                END IF;
            END");

        DB::unprepared("CREATE TRIGGER ffb_parallel_run_link_guard_update BEFORE UPDATE ON ffb_import_batches
            FOR EACH ROW
            BEGIN
                IF NEW.receipt_date BETWEEN '2026-11-05' AND '2026-11-30'
                    AND NEW.daily_operation_id IS NOT NULL
                    AND (OLD.daily_operation_id IS NULL OR NOT (OLD.daily_operation_id <=> NEW.daily_operation_id)) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Parallel Run FFB batches cannot be linked to daily operations';
                END IF;
            END");

        DB::unprepared("CREATE TRIGGER ffb_orphan_guard_insert BEFORE INSERT ON ffb_import_batches
            FOR EACH ROW
            BEGIN
                DECLARE v_locked_mill_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE v_daily_operation_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_daily_operation_id = NULL;

                IF NEW.status = 'confirmed' AND NEW.receipt_date >= '2026-12-01' THEN
                    SELECT id INTO v_locked_mill_id FROM mills WHERE id = NEW.mill_id FOR UPDATE;
                    SELECT id INTO v_daily_operation_id FROM daily_operations
                        WHERE mill_id = NEW.mill_id AND tarikh = NEW.receipt_date
                        LIMIT 1 FOR UPDATE;
                    IF v_daily_operation_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'FFB confirmation blocked: daily operation already exists for mill and date';
                    END IF;
                END IF;
            END");

        DB::unprepared("CREATE TRIGGER ffb_orphan_guard_update BEFORE UPDATE ON ffb_import_batches
            FOR EACH ROW
            BEGIN
                DECLARE v_locked_mill_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE v_daily_operation_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_daily_operation_id = NULL;

                IF OLD.status <> 'confirmed' AND NEW.status = 'confirmed'
                    AND NEW.receipt_date >= '2026-12-01' THEN
                    SELECT id INTO v_locked_mill_id FROM mills WHERE id = NEW.mill_id FOR UPDATE;
                    SELECT id INTO v_daily_operation_id FROM daily_operations
                        WHERE mill_id = NEW.mill_id AND tarikh = NEW.receipt_date
                        LIMIT 1 FOR UPDATE;
                    IF v_daily_operation_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'FFB confirmation blocked: daily operation already exists for mill and date';
                    END IF;
                END IF;
            END");

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            $identityChange = $event === 'UPDATE'
                ? ' AND (OLD.mill_id <> NEW.mill_id OR OLD.tarikh <> NEW.tarikh)'
                : '';
            DB::unprepared("CREATE TRIGGER daily_confirmed_batch_guard_{$suffix} BEFORE {$event} ON daily_operations
                FOR EACH ROW
                BEGIN
                    DECLARE v_locked_mill_id BIGINT UNSIGNED DEFAULT NULL;
                    DECLARE v_confirmed_batch_id BIGINT UNSIGNED DEFAULT NULL;
                    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_confirmed_batch_id = NULL;

                    SELECT id INTO v_locked_mill_id FROM mills WHERE id = NEW.mill_id FOR UPDATE;
                    IF NEW.ffb_import_batch_id IS NOT NULL AND EXISTS (
                        SELECT 1 FROM ffb_import_batches
                        WHERE id = NEW.ffb_import_batch_id
                            AND receipt_date BETWEEN '2026-11-05' AND '2026-11-30'
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Parallel Run FFB batches cannot be linked to daily operations';
                    END IF;
                    IF NEW.tarikh >= '2026-12-01' THEN
                        SELECT id INTO v_confirmed_batch_id FROM ffb_import_batches
                            WHERE mill_id = NEW.mill_id AND receipt_date = NEW.tarikh AND active_slot = 1
                            LIMIT 1 FOR UPDATE;
                        IF v_confirmed_batch_id IS NOT NULL
                            AND NOT (NEW.ffb_import_batch_id <=> v_confirmed_batch_id){$identityChange} THEN
                            SIGNAL SQLSTATE '45000'
                                SET MESSAGE_TEXT = 'A confirmed FFB receipt must be linked to the daily operation';
                        END IF;
                    END IF;
                END");
        }
    }

    public function down(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
