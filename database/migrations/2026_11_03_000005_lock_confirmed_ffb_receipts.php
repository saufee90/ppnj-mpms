<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('FFB permanent receipt guards require MySQL.');
        }
        DB::statement("ALTER TABLE daily_operations MODIFY operation_status ENUM('Operasi', 'Tidak Operasi (Terima Buah Sahaja)', 'Tidak Operasi (Tutup Sepenuhnya)') NOT NULL DEFAULT 'Operasi'");

        $columns = array_diff(Schema::getColumnListing('ffb_import_batches'), ['active_slot', 'daily_operation_id', 'synced_at', 'updated_at']);
        $changed = implode(' OR ', array_map(fn ($column) => "NOT (OLD.`{$column}` <=> NEW.`{$column}`)", $columns));
        DB::unprepared("CREATE TRIGGER ffb_batches_locked_update BEFORE UPDATE ON ffb_import_batches FOR EACH ROW
            BEGIN
                IF NEW.receipt_type='zero_receipt' AND EXISTS (SELECT 1 FROM ffb_weighbridge_tickets t WHERE t.ffb_import_batch_id=OLD.id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zero receipt cannot contain tickets';
                END IF;
                IF OLD.status IN ('confirmed', 'superseded') THEN
                    IF {$changed} THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Confirmed FFB receipt is permanently locked';
                    END IF;
                    IF NOT (OLD.daily_operation_id <=> NEW.daily_operation_id) OR NOT (OLD.synced_at <=> NEW.synced_at) THEN
                        IF OLD.daily_operation_id IS NOT NULL OR OLD.synced_at IS NOT NULL
                            OR NEW.daily_operation_id IS NULL OR NEW.synced_at IS NULL
                            OR NOT EXISTS (SELECT 1 FROM daily_operations d WHERE d.id=NEW.daily_operation_id
                                AND d.ffb_import_batch_id=OLD.id AND d.mill_id=OLD.mill_id
                                AND d.tarikh=OLD.receipt_date AND d.shift='Harian'
                                AND d.bts_diterima=OLD.confirmed_daily_total_mt) THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid one-time FFB daily association';
                        END IF;
                    END IF;
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER ffb_batches_locked_delete BEFORE DELETE ON ffb_import_batches FOR EACH ROW
            BEGIN
                IF OLD.status IN ('confirmed', 'superseded') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Confirmed FFB receipt cannot be deleted';
                END IF;
            END");
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $conditions = [];
            foreach ($event === 'UPDATE' ? ['OLD', 'NEW'] : [$event === 'DELETE' ? 'OLD' : 'NEW'] as $row) {
                $conditions[] = "EXISTS (SELECT 1 FROM ffb_import_batches b WHERE b.id={$row}.ffb_import_batch_id AND (b.status IN ('confirmed','superseded') OR b.receipt_type='zero_receipt'))";
            }
            $condition = implode(' OR ', $conditions);
            $name = 'ffb_tickets_locked_'.strtolower($event);
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON ffb_weighbridge_tickets FOR EACH ROW
                BEGIN
                    IF {$condition} THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FFB tickets are locked or receipt is zero';
                    END IF;
                END");
        }
        $valid = "EXISTS (SELECT 1 FROM ffb_import_batches b WHERE b.id=NEW.ffb_import_batch_id
            AND b.status='confirmed' AND b.mill_id=NEW.mill_id AND b.receipt_date=NEW.tarikh
            AND NEW.shift='Harian' AND b.confirmed_daily_total_mt=NEW.bts_diterima)";
        foreach (['INSERT', 'UPDATE'] as $event) {
            $immutable = $event === 'UPDATE' ? "
                IF OLD.ffb_import_batch_id IS NOT NULL AND (
                    NOT (OLD.ffb_import_batch_id <=> NEW.ffb_import_batch_id)
                    OR OLD.mill_id<>NEW.mill_id OR OLD.tarikh<>NEW.tarikh OR OLD.shift<>NEW.shift
                    OR OLD.bts_diterima<>NEW.bts_diterima) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Daily FFB receipt and identity are locked';
                END IF;
                IF OLD.ffb_import_batch_id IS NULL AND NEW.ffb_import_batch_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing daily records cannot be retroactively linked';
                END IF;
                IF OLD.ffb_import_batch_id IS NULL AND OLD.tarikh < '2026-12-01' AND NEW.tarikh >= '2026-12-01' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historical daily record cannot cross FFB rollout boundary';
                END IF;" : "
                IF NEW.tarikh >= '2026-12-01' AND NEW.ffb_import_batch_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Confirmed FFB receipt required from 2026-12-01';
                END IF;";
            DB::unprepared("CREATE TRIGGER daily_ffb_".strtolower($event)." BEFORE {$event} ON daily_operations FOR EACH ROW
                BEGIN
                    {$immutable}
                    IF NEW.ffb_import_batch_id IS NOT NULL AND NOT ({$valid}) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FFB batch does not match daily receipt';
                    END IF;
                    IF NEW.operation_status='Tidak Operasi (Tutup Sepenuhnya)' AND (
                        NEW.ffb_import_batch_id IS NULL OR NEW.bts_diterima<>0 OR NEW.bts_diproses<>0
                        OR NEW.jam_operasi<>0 OR NEW.downtime_jam<>0 OR NEW.pengeluaran_cpo<>0
                        OR NEW.pengeluaran_pk<>0 OR NEW.pk_kcp_to_hopper<>0
                        OR COALESCE(NEW.produksi_cpo,0)<>0 OR COALESCE(NEW.produksi_pk,0)<>0) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fully closed day requires confirmed zero receipt and zero activity';
                    END IF;
                END");
        }
        DB::unprepared("CREATE TRIGGER daily_ffb_delete BEFORE DELETE ON daily_operations FOR EACH ROW
            BEGIN
                IF OLD.ffb_import_batch_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FFB-linked daily record cannot be deleted';
                END IF;
            END");
        foreach (['UPDATE', 'DELETE'] as $event) {
            $condition = $event === 'UPDATE'
                ? "LEFT(OLD.action,4)='ffb_' OR LEFT(NEW.action,4)='ffb_'"
                : "LEFT(OLD.action,4)='ffb_'";
            DB::unprepared("CREATE TRIGGER ffb_audit_locked_".strtolower($event)." BEFORE {$event} ON audit_logs FOR EACH ROW
                BEGIN
                    IF {$condition} THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FFB audit history is append-only';
                    END IF;
                END");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Permanent FFB locks require a reviewed forward migration; automatic rollback is disabled.');
    }
};
