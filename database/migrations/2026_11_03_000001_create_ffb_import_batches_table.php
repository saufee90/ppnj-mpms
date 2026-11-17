<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ffb_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mill_id')->constrained('mills')->restrictOnDelete();
            $table->date('receipt_date');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_filename');
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('mime_type')->nullable();
            $table->string('detected_format', 10)->nullable();
            $table->enum('status', [
                'uploaded', 'needs_review', 'ready', 'confirmed',
                'superseded', 'failed', 'cancelled',
            ])->default('uploaded');
            $table->string('parser_version')->nullable();
            $table->string('resolver_version')->nullable();
            $table->enum('confirmed_weight_unit', ['KG', 'MT'])->nullable();
            $table->unsignedInteger('ticket_count')->nullable();
            $table->decimal('parsed_total_mt', 15, 6)->nullable();
            $table->boolean('has_complete_weight_total')->default(false);
            $table->unsignedInteger('unresolved_count')->nullable();
            $table->json('validation_summary')->nullable();
            $table->unsignedInteger('control_ticket_count')->nullable();
            $table->decimal('control_total_mt', 15, 6)->nullable();
            $table->json('reconciliation_evidence')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->char('preview_hash', 64)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('confirmation_reason')->nullable();
            $table->json('confirmation_snapshot')->nullable();
            $table->decimal('confirmed_daily_total_mt', 10, 2)->nullable();
            $table->foreignId('supersedes_batch_id')->nullable()
                ->constrained('ffb_import_batches')->restrictOnDelete();
            $table->foreignId('daily_operation_id')->nullable()
                ->constrained('daily_operations')->restrictOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // NULL leaves candidate and historical revisions outside the active slot.
            $table->unsignedTinyInteger('active_slot')->nullable()
                ->virtualAs("CASE WHEN status = 'confirmed' THEN 1 ELSE NULL END");
            $table->unique(['mill_id', 'receipt_date', 'active_slot'], 'ffb_batches_current_unique');
            $table->index(['mill_id', 'receipt_date'], 'ffb_batches_mill_date_index');
            $table->index('file_sha256');
        });

        // SQLite cannot add table CHECK constraints after creation.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE ffb_import_batches
                ADD CONSTRAINT ffb_batches_confirmation_check CHECK (
                    status NOT IN ('confirmed', 'superseded') OR (
                        confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL
                        AND confirmed_daily_total_mt IS NOT NULL
                        AND confirmed_daily_total_mt >= 0
                        AND confirmation_snapshot IS NOT NULL
                        AND validated_at IS NOT NULL AND preview_hash IS NOT NULL
                    )
                )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ffb_import_batches');
    }
};
