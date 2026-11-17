<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keep invalid/duplicate candidates and original evidence; never derive historical
 * source labels from mutable masters instead of the stored resolution snapshot.
 */
class FfbWeighbridgeTicket extends Model
{
    protected $fillable = [
        'ffb_import_batch_id', 'worksheet', 'row_number', 'heading_row_number',
        'original_heading', 'original_source_code', 'ticket_number', 'normalized_ticket_number',
        'ticket_date', 'do_number', 'vehicle_number', 'raw_values',
        'weight_unit', 'weight_unit_origin', 'weight_mt',
        'ffb_source_group_id', 'weighbridge_centre_id', 'ppk_id', 'source_category',
        'resolution_snapshot', 'requires_review', 'review_reasons',
    ];

    protected $attributes = ['requires_review' => true];

    protected function casts(): array
    {
        return [
            'ffb_import_batch_id' => 'integer',
            'row_number' => 'integer',
            'heading_row_number' => 'integer',
            'ticket_date' => 'date',
            'raw_values' => 'array',
            'weight_mt' => 'decimal:6',
            'ffb_source_group_id' => 'integer',
            'weighbridge_centre_id' => 'integer',
            'ppk_id' => 'integer',
            'resolution_snapshot' => 'array',
            'requires_review' => 'boolean',
            'review_reasons' => 'array',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(FfbImportBatch::class, 'ffb_import_batch_id');
    }

    public function sourceGroup(): BelongsTo
    {
        return $this->belongsTo(FfbSourceGroup::class, 'ffb_source_group_id');
    }

    public function weighbridgeCentre(): BelongsTo
    {
        return $this->belongsTo(WeighbridgeCentre::class);
    }

    public function ppk(): BelongsTo
    {
        return $this->belongsTo(Ppk::class);
    }
}
