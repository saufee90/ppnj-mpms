
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException(
                'FFB trigger repair requires MySQL/MariaDB.'
            );
        }

        $columns = array_diff(
            Schema::getColumnListing('daily_operations'),
            [
                'ffb_import_batch_id',
                'bts_diterima',
                'baki_bts_semalam',
                'baki_bts_selepas_diproses',
                'last_amendment_reason',
                'last_amended_by',
                'last_amended_at',
                'updated_at',
            ]
        );

        $changed = implode(' OR ', array_map(
            fn (string $column): string =>
                "NOT (OLD.`{$column}` <=> NEW.`{$column}`)",
            $columns
        )) ?: 'FALSE';

        DB::unprepared('DROP TRIGGER IF EXISTS daily_ffb_insert');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER daily_ffb_insert
            BEFORE INSERT ON daily_operations
            FOR EACH ROW
            BEGIN
                IF NEW.tarikh >= '2026-12-01'
                    AND NEW.ffb_import_batch_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Confirmed FFB receipt required from 2026-12-01';
                END IF;

                IF NEW.ffb_import_batch_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM ffb_import_batches b
                    WHERE b.id = NEW.ffb_import_batch_id
                        AND b.status = 'confirmed'
                        AND b.mill_id = NEW.mill_id
                        AND b.receipt_date = NEW.tarikh
                        AND NEW.shift = 'Harian'
                        AND b.confirmed_daily_total_mt = NEW.bts_diterima
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'FFB batch does not match daily receipt';
                END IF;

                IF NEW.tarikh >= '2026-12-01'
                    AND NEW.operation_status = 'Tidak Operasi (Tutup Sepenuhnya)'
                    AND (
                        NEW.ffb_import_batch_id IS NULL
                        OR NEW.bts_diterima <> 0
                        OR NEW.bts_diproses <> 0
                        OR NEW.jam_operasi <> 0
                        OR NEW.downtime_jam <> 0
                        OR NEW.pengeluaran_cpo <> 0
                        OR NEW.pengeluaran_pk <> 0
                        OR NEW.pk_kcp_to_hopper <> 0
                        OR COALESCE(NEW.produksi_cpo, 0) <> 0
                        OR COALESCE(NEW.produksi_pk, 0) <> 0
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Fully closed day requires confirmed zero receipt and zero activity';
                END IF;
            END
            SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS daily_ffb_update');

        DB::unprepared(<<<SQL
            CREATE TRIGGER daily_ffb_update
            BEFORE UPDATE ON daily_operations
            FOR EACH ROW
            BEGIN
                DECLARE v_expected_opening DECIMAL(15,2) DEFAULT 0;
                DECLARE v_previous_closing DECIMAL(15,2) DEFAULT 0;

                IF OLD.ffb_import_batch_id IS NOT NULL AND (
                    OLD.mill_id <> NEW.mill_id
                    OR OLD.tarikh <> NEW.tarikh
                    OR OLD.shift <> NEW.shift
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Linked FFB operation identity is locked';
                END IF;

                IF OLD.ffb_import_batch_id IS NOT NULL AND (
                    NOT (OLD.ffb_import_batch_id <=> NEW.ffb_import_batch_id)
                    OR NOT (OLD.bts_diterima <=> NEW.bts_diterima)
                ) THEN
                    IF NEW.tarikh < '2026-12-01'
                        OR {$changed}
                        OR NOT EXISTS (
                            SELECT 1
                            FROM ffb_import_batches old_batch
                            JOIN ffb_import_batches new_batch
                                ON new_batch.supersedes_batch_id = old_batch.id
                            WHERE old_batch.id = OLD.ffb_import_batch_id
                                AND old_batch.status = 'superseded'
                                AND new_batch.id = NEW.ffb_import_batch_id
                                AND new_batch.status = 'confirmed'
                                AND old_batch.mill_id = NEW.mill_id
                                AND old_batch.receipt_date = NEW.tarikh
                                AND new_batch.mill_id = NEW.mill_id
                                AND new_batch.receipt_date = NEW.tarikh
                                AND new_batch.confirmed_daily_total_mt = NEW.bts_diterima
                                AND old_batch.daily_operation_id = OLD.id
                        ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Daily BTS may change only to a confirmed same-day correction';
                    END IF;

                    IF DAY(NEW.tarikh) = 1 THEN
                        SET v_expected_opening = 0;
                    ELSE
                        SELECT COALESCE((
                            SELECT d.baki_bts_selepas_diproses
                            FROM daily_operations d
                            WHERE d.mill_id = NEW.mill_id
                                AND d.tarikh < NEW.tarikh
                            ORDER BY d.tarikh DESC, d.id DESC
                            LIMIT 1
                        ), 0) INTO v_previous_closing;

                        SET v_expected_opening = v_previous_closing;
                    END IF;

                    IF NEW.baki_bts_semalam <> v_expected_opening
                        OR NEW.baki_bts_selepas_diproses <> ROUND(
                            NEW.baki_bts_semalam + NEW.bts_diterima
                            - IF(
                                NEW.operation_status = 'Operasi',
                                NEW.bts_diproses,
                                0
                            ), 2
                        ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Corrected operation has an invalid BTS balance';
                    END IF;

                ELSEIF OLD.ffb_import_batch_id IS NOT NULL AND (
                    NOT (OLD.baki_bts_semalam <=> NEW.baki_bts_semalam)
                    OR NOT (
                        OLD.baki_bts_selepas_diproses
                        <=> NEW.baki_bts_selepas_diproses
                    )
                ) THEN
                    IF {$changed} THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'BTS balance correction cannot alter unrelated operation fields';
                    END IF;

                    IF DAY(NEW.tarikh) = 1 THEN
                        SET v_expected_opening = 0;
                    ELSE
                        SELECT COALESCE((
                            SELECT d.baki_bts_selepas_diproses
                            FROM daily_operations d
                            WHERE d.mill_id = NEW.mill_id
                                AND d.tarikh < NEW.tarikh
                            ORDER BY d.tarikh DESC, d.id DESC
                            LIMIT 1
                        ), 0) INTO v_previous_closing;

                        SET v_expected_opening = v_previous_closing;
                    END IF;

                    IF NEW.baki_bts_semalam <> v_expected_opening
                        OR NEW.baki_bts_selepas_diproses <> ROUND(
                            NEW.baki_bts_semalam + NEW.bts_diterima
                            - IF(
                                NEW.operation_status = 'Operasi',
                                NEW.bts_diproses,
                                0
                            ), 2
                        ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'BTS balance does not match the approved carry-forward calculation';
                    END IF;
                END IF;

                IF OLD.ffb_import_batch_id IS NULL
                    AND NEW.ffb_import_batch_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Existing daily records cannot be retroactively linked';
                END IF;

                IF OLD.ffb_import_batch_id IS NULL
                    AND OLD.tarikh < '2026-12-01'
                    AND NEW.tarikh >= '2026-12-01' THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Historical daily record cannot cross FFB rollout boundary';
                END IF;

                IF NEW.ffb_import_batch_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM ffb_import_batches b
                    WHERE b.id = NEW.ffb_import_batch_id
                        AND b.status = 'confirmed'
                        AND b.mill_id = NEW.mill_id
                        AND b.receipt_date = NEW.tarikh
                        AND NEW.shift = 'Harian'
                        AND b.confirmed_daily_total_mt = NEW.bts_diterima
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'FFB batch does not match daily receipt';
                END IF;

                IF NEW.tarikh >= '2026-12-01'
                    AND NEW.operation_status = 'Tidak Operasi (Tutup Sepenuhnya)'
                    AND (
                        NEW.ffb_import_batch_id IS NULL
                        OR NEW.bts_diterima <> 0
                        OR NEW.bts_diproses <> 0
                        OR NEW.jam_operasi <> 0
                        OR NEW.downtime_jam <> 0
                        OR NEW.pengeluaran_cpo <> 0
                        OR NEW.pengeluaran_pk <> 0
                        OR NEW.pk_kcp_to_hopper <> 0
                        OR COALESCE(NEW.produksi_cpo, 0) <> 0
                        OR COALESCE(NEW.produksi_pk, 0) <> 0
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Fully closed day requires confirmed zero receipt and zero activity';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'FFB trigger repair is forward-only; rollback requires a reviewed migration.'
        );
    }
};

