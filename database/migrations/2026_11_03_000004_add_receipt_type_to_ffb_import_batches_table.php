<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->requireMysql();

        DB::statement(<<<'SQL'
            ALTER TABLE ffb_import_batches
                ADD COLUMN receipt_type ENUM('excel', 'zero_receipt') NOT NULL DEFAULT 'excel',
                MODIFY COLUMN original_filename VARCHAR(255) NULL,
                MODIFY COLUMN storage_disk VARCHAR(255) NULL,
                MODIFY COLUMN storage_path VARCHAR(255) NULL,
                MODIFY COLUMN file_sha256 CHAR(64) NULL,
                MODIFY COLUMN file_size_bytes BIGINT UNSIGNED NULL,
                ADD CONSTRAINT ffb_batches_receipt_evidence_check CHECK (
                    (
                        receipt_type = 'excel'
                        AND original_filename IS NOT NULL
                        AND storage_disk IS NOT NULL
                        AND storage_path IS NOT NULL
                        AND file_sha256 IS NOT NULL
                        AND file_size_bytes IS NOT NULL
                    ) OR (
                        receipt_type = 'zero_receipt'
                        AND original_filename IS NULL
                        AND storage_disk IS NULL
                        AND storage_path IS NULL
                        AND file_sha256 IS NULL
                        AND file_size_bytes IS NULL
                    )
                ),
                ADD CONSTRAINT ffb_batches_zero_receipt_totals_check CHECK (
                    receipt_type <> 'zero_receipt' OR (
                        ticket_count IS NOT NULL AND ticket_count = 0
                        AND parsed_total_mt IS NOT NULL AND parsed_total_mt = 0
                        AND (
                            (
                                status IN ('confirmed', 'superseded')
                                AND confirmed_daily_total_mt IS NOT NULL
                                AND confirmed_daily_total_mt = 0
                            ) OR (
                                status NOT IN ('confirmed', 'superseded')
                                AND confirmed_daily_total_mt IS NULL
                            )
                        )
                        AND unresolved_count IS NOT NULL AND unresolved_count = 0
                        AND has_complete_weight_total = 1
                        AND (control_ticket_count IS NULL OR control_ticket_count = 0)
                        AND (control_total_mt IS NULL OR control_total_mt = 0)
                    )
                )
            SQL);
    }

    public function down(): void
    {
        $this->requireMysql();

        if (DB::table('ffb_import_batches')->where('receipt_type', '<>', 'excel')->exists()) {
            throw new RuntimeException('Cannot roll back receipt types while non-Excel FFB records exist. Preserve these records and use a forward migration.');
        }

        DB::statement(<<<'SQL'
            ALTER TABLE ffb_import_batches
                DROP CHECK ffb_batches_receipt_evidence_check,
                DROP CHECK ffb_batches_zero_receipt_totals_check,
                MODIFY COLUMN original_filename VARCHAR(255) NOT NULL,
                MODIFY COLUMN storage_disk VARCHAR(255) NOT NULL,
                MODIFY COLUMN storage_path VARCHAR(255) NOT NULL,
                MODIFY COLUMN file_sha256 CHAR(64) NOT NULL,
                MODIFY COLUMN file_size_bytes BIGINT UNSIGNED NOT NULL,
                DROP COLUMN receipt_type
            SQL);
    }

    private function requireMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('This FFB receipt-type migration requires MySQL with enforced CHECK constraints (target: MySQL 8.4.3).');
        }
    }
};
