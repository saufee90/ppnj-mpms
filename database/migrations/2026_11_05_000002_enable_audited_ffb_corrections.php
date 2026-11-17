<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->requireStage3Database();

        $batchColumns = array_diff(
            Schema::getColumnListing('ffb_import_batches'),
            ['status', 'active_slot', 'daily_operation_id', 'synced_at', 'updated_at'],
        );
        $batchContentChanged = $this->changedColumns('ffb_import_batches', $batchColumns);
        $dailyAllowed = [
            'ffb_import_batch_id', 'bts_diterima', 'baki_bts_semalam',
            'baki_bts_selepas_diproses', 'last_amendment_reason',
            'last_amended_by', 'last_amended_at', 'updated_at',
        ];
        $dailyContentChanged = $this->changedColumns(
            'daily_operations',
            array_diff(Schema::getColumnListing('daily_operations'), $dailyAllowed),
        );

        DB::unprepared('DROP TRIGGER IF EXISTS ffb_batches_locked_update');
        DB::unprepared(<<<SQL
            CREATE TRIGGER ffb_batches_locked_update BEFORE UPDATE ON ffb_import_batches
            FOR EACH ROW
            BEGIN
                IF NEW.receipt_type='zero_receipt' AND EXISTS (
                    SELECT 1 FROM ffb_weighbridge_tickets t WHERE t.ffb_import_batch_id=OLD.id
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Zero receipt cannot contain tickets';
                END IF;

                IF OLD.status='confirmed' AND NEW.status='superseded' THEN
                    IF {$batchContentChanged}
                        OR NOT (OLD.daily_operation_id <=> NEW.daily_operation_id)
                        OR NOT (OLD.synced_at <=> NEW.synced_at) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Confirmed FFB evidence is immutable during correction';
                    END IF;
                    IF OLD.receipt_date < '2026-11-05'
                        OR TRIM(COALESCE((
                            SELECT c.confirmation_reason FROM ffb_import_batches c
                            WHERE c.supersedes_batch_id=OLD.id AND c.status='ready'
                                AND c.mill_id=OLD.mill_id AND c.receipt_date=OLD.receipt_date
                                AND c.confirmation_reason IS NOT NULL AND TRIM(c.confirmation_reason)<>''
                                AND c.validated_at IS NOT NULL AND c.preview_hash IS NOT NULL
                            LIMIT 1
                        ),''))='' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Valid replacement batch and correction reason are required';
                    END IF;
                    IF OLD.daily_operation_id IS NOT NULL AND (
                        OLD.receipt_date < '2026-12-01'
                        OR NOT EXISTS (
                            SELECT 1 FROM daily_operations d
                            WHERE d.id=OLD.daily_operation_id AND d.ffb_import_batch_id=OLD.id
                                AND d.mill_id=OLD.mill_id AND d.tarikh=OLD.receipt_date
                                AND d.shift='Harian' AND d.bts_diterima=OLD.confirmed_daily_total_mt
                        )
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Parallel Run or mismatched FFB links cannot be corrected';
                    END IF;
                ELSEIF OLD.status='confirmed' THEN
                    IF {$batchContentChanged} OR NEW.status<>'confirmed' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Confirmed FFB receipt is locked';
                    END IF;
                    IF NOT (OLD.daily_operation_id <=> NEW.daily_operation_id)
                        OR NOT (OLD.synced_at <=> NEW.synced_at) THEN
                        IF OLD.daily_operation_id IS NOT NULL OR OLD.synced_at IS NOT NULL
                            OR NEW.daily_operation_id IS NULL OR NEW.synced_at IS NULL
                            OR NOT EXISTS (
                                SELECT 1 FROM daily_operations d
                                WHERE d.id=NEW.daily_operation_id AND d.ffb_import_batch_id=OLD.id
                                    AND d.mill_id=OLD.mill_id AND d.tarikh=OLD.receipt_date
                                    AND d.shift='Harian' AND d.bts_diterima=OLD.confirmed_daily_total_mt
                            ) THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid one-time FFB daily association';
                        END IF;
                    END IF;
                ELSEIF OLD.status='superseded' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Superseded FFB history is immutable';
                END IF;
            END
            SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS ffb_orphan_guard_update');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ffb_orphan_guard_update BEFORE UPDATE ON ffb_import_batches
            FOR EACH ROW
            BEGIN
                DECLARE v_daily_operation_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_daily_operation_id = NULL;

                IF OLD.status<>'confirmed' AND NEW.status='confirmed' AND NEW.receipt_date>='2026-12-01' THEN
                    SELECT id INTO v_daily_operation_id FROM daily_operations
                    WHERE mill_id=NEW.mill_id AND tarikh=NEW.receipt_date
                    LIMIT 1 FOR UPDATE;

                    IF NEW.supersedes_batch_id IS NULL AND v_daily_operation_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='FFB confirmation blocked: daily operation already exists';
                    END IF;

                    IF NEW.supersedes_batch_id IS NOT NULL THEN
                        IF NOT EXISTS (
                            SELECT 1 FROM ffb_import_batches p
                            WHERE p.id=NEW.supersedes_batch_id AND p.status='superseded'
                                AND p.mill_id=NEW.mill_id AND p.receipt_date=NEW.receipt_date
                        ) THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction predecessor is not superseded for this mill and date';
                        END IF;

                        IF v_daily_operation_id IS NOT NULL AND NOT EXISTS (
                            SELECT 1 FROM daily_operations d
                            JOIN ffb_import_batches p ON p.id=NEW.supersedes_batch_id
                            WHERE d.id=v_daily_operation_id AND d.mill_id=NEW.mill_id
                                AND d.tarikh=NEW.receipt_date AND d.shift='Harian'
                                AND d.ffb_import_batch_id=p.id AND p.daily_operation_id=d.id
                        ) THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Existing daily operation is not linked to the correction predecessor';
                        END IF;

                        IF v_daily_operation_id IS NULL AND EXISTS (
                            SELECT 1 FROM ffb_import_batches p
                            WHERE p.id=NEW.supersedes_batch_id AND p.daily_operation_id IS NOT NULL
                        ) THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction predecessor has an inconsistent daily link';
                        END IF;
                    END IF;
                END IF;
            END
            SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS daily_ffb_update');
        DB::unprepared(<<<SQL
            CREATE TRIGGER daily_ffb_update BEFORE UPDATE ON daily_operations
            FOR EACH ROW
            BEGIN
                DECLARE v_expected_opening DECIMAL(15,2) DEFAULT 0;
                DECLARE v_previous_closing DECIMAL(15,2) DEFAULT 0;

                IF OLD.ffb_import_batch_id IS NOT NULL AND (
                    OLD.mill_id<>NEW.mill_id OR OLD.tarikh<>NEW.tarikh OR OLD.shift<>NEW.shift
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Linked FFB operation identity is locked';
                END IF;

                IF OLD.ffb_import_batch_id IS NOT NULL AND (
                    NOT (OLD.ffb_import_batch_id <=> NEW.ffb_import_batch_id)
                    OR NOT (OLD.bts_diterima <=> NEW.bts_diterima)
                ) THEN
                    IF NEW.tarikh<'2026-12-01' OR {$dailyContentChanged}
                        OR NOT EXISTS (
                            SELECT 1 FROM ffb_import_batches old_batch
                            JOIN ffb_import_batches new_batch
                                ON new_batch.supersedes_batch_id=old_batch.id
                            WHERE old_batch.id=OLD.ffb_import_batch_id
                                AND old_batch.status='superseded'
                                AND new_batch.id=NEW.ffb_import_batch_id
                                AND new_batch.status='confirmed'
                                AND old_batch.mill_id=NEW.mill_id
                                AND old_batch.receipt_date=NEW.tarikh
                                AND new_batch.mill_id=NEW.mill_id
                                AND new_batch.receipt_date=NEW.tarikh
                                AND new_batch.confirmed_daily_total_mt=NEW.bts_diterima
                                AND old_batch.daily_operation_id=OLD.id
                        ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Daily BTS may change only to a confirmed same-day correction';
                    END IF;
                    IF DAY(NEW.tarikh)=1 THEN
                        SET v_expected_opening=0;
                    ELSE
                        SELECT COALESCE((
                        SELECT d.baki_bts_selepas_diproses FROM daily_operations d
                        WHERE d.mill_id=NEW.mill_id AND d.tarikh<NEW.tarikh
                        ORDER BY d.tarikh DESC, d.id DESC LIMIT 1
                        ),0) INTO v_previous_closing;
                        SET v_expected_opening=v_previous_closing;
                    END IF;
                    IF NEW.baki_bts_semalam<>v_expected_opening
                        OR NEW.baki_bts_selepas_diproses<>ROUND(
                        NEW.baki_bts_semalam + NEW.bts_diterima
                            - IF(NEW.operation_status='Operasi',NEW.bts_diproses,0),2
                        ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Corrected operation has an invalid BTS balance';
                    END IF;
                ELSEIF OLD.ffb_import_batch_id IS NOT NULL AND (
                    NOT (OLD.baki_bts_semalam <=> NEW.baki_bts_semalam)
                    OR NOT (OLD.baki_bts_selepas_diproses <=> NEW.baki_bts_selepas_diproses)
                ) THEN
                    IF {$dailyContentChanged} THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='BTS balance correction cannot alter unrelated operation fields';
                    END IF;
                    IF DAY(NEW.tarikh)=1 THEN
                        SET v_expected_opening=0;
                    ELSE
                        SELECT COALESCE((
                            SELECT d.baki_bts_selepas_diproses FROM daily_operations d
                            WHERE d.mill_id=NEW.mill_id AND d.tarikh<NEW.tarikh
                            ORDER BY d.tarikh DESC, d.id DESC LIMIT 1
                        ),0) INTO v_previous_closing;
                        SET v_expected_opening=v_previous_closing;
                    END IF;
                    IF NEW.baki_bts_semalam<>v_expected_opening
                        OR NEW.baki_bts_selepas_diproses<>ROUND(
                            NEW.baki_bts_semalam + NEW.bts_diterima
                                - IF(NEW.operation_status='Operasi',NEW.bts_diproses,0),2
                        ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='BTS balance does not match the approved carry-forward calculation';
                    END IF;
                END IF;

                IF OLD.ffb_import_batch_id IS NULL AND NEW.ffb_import_batch_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Existing daily records cannot be retroactively linked';
                END IF;
                IF OLD.ffb_import_batch_id IS NULL AND OLD.tarikh<'2026-12-01' AND NEW.tarikh>='2026-12-01' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical daily record cannot cross FFB rollout boundary';
                END IF;
                IF NEW.ffb_import_batch_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM ffb_import_batches b
                    WHERE b.id=NEW.ffb_import_batch_id AND b.status='confirmed'
                        AND b.mill_id=NEW.mill_id AND b.receipt_date=NEW.tarikh
                        AND NEW.shift='Harian' AND b.confirmed_daily_total_mt=NEW.bts_diterima
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='FFB batch does not match daily receipt';
                END IF;
                IF NEW.operation_status='Tidak Operasi (Tutup Sepenuhnya)' AND (
                    NEW.ffb_import_batch_id IS NULL OR NEW.bts_diterima<>0 OR NEW.bts_diproses<>0
                    OR NEW.jam_operasi<>0 OR NEW.downtime_jam<>0 OR NEW.pengeluaran_cpo<>0
                    OR NEW.pengeluaran_pk<>0 OR NEW.pk_kcp_to_hopper<>0
                    OR COALESCE(NEW.produksi_cpo,0)<>0 OR COALESCE(NEW.produksi_pk,0)<>0
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fully closed day requires confirmed zero receipt and zero activity';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('FFB correction protections are forward-only; use a reviewed repair migration.');
    }

    private function changedColumns(string $table, array $columns): string
    {
        return implode(' OR ', array_map(
            fn (string $column): string => "NOT (OLD.`{$column}` <=> NEW.`{$column}`)",
            $columns,
        )) ?: 'FALSE';
    }

    private function requireStage3Database(): void
    {
        $connection = DB::connection();
        $database = $connection->selectOne('SELECT DATABASE() AS database_name, VERSION() AS version');

        $allowed = $database->database_name === 'mpms_ffb_stage3_test'
            || ($database->database_name === 'mpms_ffb_master_uat'
                && app()->environment() === 'master-uat'
                && in_array($connection->getConfig('host'), ['localhost', '127.0.0.1'], true)
                && $connection->getDatabaseName() === 'mpms_ffb_master_uat');

        if ($connection->getDriverName() !== 'mysql'
            || ! $allowed
            || ! str_starts_with($database->version, '8.4.3')) {
            throw new RuntimeException('Stage 3 migration is restricted to approved isolated MySQL 8.4.3 databases.');
        }
    }
};
