<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Excel evidence or a zero-receipt declaration for one mill/day.
 * Confirmed content is permanent; only the one-time daily association may be added.
 */
class FfbImportBatch extends Model
{
    public const SYNC_SHIFT = 'Harian';

    public const STATUSES = [
        'uploaded', 'needs_review', 'ready', 'confirmed',
        'superseded', 'failed', 'cancelled',
    ];

    protected $fillable = [
        'mill_id', 'receipt_date', 'uploaded_by', 'receipt_type',
        'original_filename', 'storage_disk', 'storage_path', 'file_sha256', 'file_size_bytes',
        'mime_type', 'detected_format', 'status', 'parser_version', 'resolver_version',
        'confirmed_weight_unit', 'ticket_count', 'parsed_total_mt', 'has_complete_weight_total',
        'unresolved_count', 'validation_summary', 'control_ticket_count', 'control_total_mt',
        'reconciliation_evidence', 'validated_at', 'preview_hash',
        'confirmed_by', 'confirmed_at', 'confirmation_reason', 'confirmation_snapshot',
        'confirmed_daily_total_mt', 'supersedes_batch_id', 'daily_operation_id', 'synced_at',
    ];

    protected $attributes = [
        'status' => 'uploaded',
        'has_complete_weight_total' => false,
    ];

    protected function casts(): array
    {
        return [
            'mill_id' => 'integer',
            'receipt_date' => 'date',
            'uploaded_by' => 'integer',
            'file_size_bytes' => 'integer',
            'ticket_count' => 'integer',
            'parsed_total_mt' => 'decimal:6',
            'has_complete_weight_total' => 'boolean',
            'unresolved_count' => 'integer',
            'validation_summary' => 'array',
            'control_ticket_count' => 'integer',
            'control_total_mt' => 'decimal:6',
            'reconciliation_evidence' => 'array',
            'validated_at' => 'datetime',
            'confirmed_by' => 'integer',
            'confirmed_at' => 'datetime',
            'confirmation_snapshot' => 'array',
            'confirmed_daily_total_mt' => 'decimal:2',
            'supersedes_batch_id' => 'integer',
            'daily_operation_id' => 'integer',
            'synced_at' => 'datetime',
            'active_slot' => 'integer',
        ];
    }

    public function mill(): BelongsTo
    {
        return $this->belongsTo(Mill::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(FfbWeighbridgeTicket::class);
    }

    public function supersedesBatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_batch_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_batch_id');
    }

    public function dailyOperation(): BelongsTo
    {
        return $this->belongsTo(DailyOperation::class);
    }
}
