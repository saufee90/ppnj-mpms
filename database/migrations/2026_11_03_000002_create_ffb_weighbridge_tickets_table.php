<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('ffb_weighbridge_tickets', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->foreignId('ffb_import_batch_id')
                ->constrained('ffb_import_batches')->restrictOnDelete();
            $worksheet = $table->string('worksheet', 31);
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $worksheet->charset('utf8mb4')->collation('utf8mb4_bin');
            }
            $table->unsignedInteger('row_number');
            $table->unsignedInteger('heading_row_number')->nullable();
            $table->text('original_heading')->nullable();
            $table->text('original_source_code')->nullable();
            $table->string('ticket_number')->nullable();
            $table->string('normalized_ticket_number')->nullable();
            $table->date('ticket_date')->nullable();
            $table->string('do_number')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->json('raw_values');
            $table->enum('weight_unit', ['KG', 'MT'])->nullable();
            $table->enum('weight_unit_origin', ['workbook', 'caller'])->nullable();
            $table->decimal('weight_mt', 15, 6)->nullable();
            $table->foreignId('ffb_source_group_id')->nullable()
                ->constrained('ffb_source_groups')->restrictOnDelete();
            $table->foreignId('weighbridge_centre_id')->nullable()
                ->constrained('weighbridge_centres')->restrictOnDelete();
            $table->foreignId('ppk_id')->nullable()->constrained('ppks')->restrictOnDelete();
            $table->enum('source_category', [
                'ppnj_owned', 'ppnj_ppk_integrated', 'external_supplier',
            ])->nullable();
            $table->json('resolution_snapshot')->nullable();
            $table->boolean('requires_review')->default(true);
            $table->json('review_reasons')->nullable();
            $table->timestamps();

            $table->unique(
                ['ffb_import_batch_id', 'worksheet', 'row_number'],
                'ffb_tickets_batch_location_unique',
            );
            $table->index(
                ['ffb_import_batch_id', 'normalized_ticket_number'],
                'ffb_tickets_batch_number_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ffb_weighbridge_tickets');
    }
};
