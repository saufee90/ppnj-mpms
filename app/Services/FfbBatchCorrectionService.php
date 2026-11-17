<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FfbImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FfbBatchCorrectionService
{
    public function createZeroReplacement(int $predecessorId, User $actor, string $reason): FfbImportBatch
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'correction_reason' => 'Sebab pembetulan wajib diisi antara 5 hingga 500 aksara.',
            ]);
        }

        $reference = FfbImportBatch::findOrFail($predecessorId);

        return DB::transaction(function () use ($reference, $actor, $reason): FfbImportBatch {
            $user = app(FfbDailyReceiptService::class)->lockActor((int) $reference->mill_id, $actor);
            $predecessor = FfbImportBatch::whereKey($reference->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('correct', $predecessor);
            $this->assertActivePredecessor($predecessor);

            if (FfbImportBatch::where('supersedes_batch_id', $predecessor->id)
                ->whereIn('status', ['uploaded', 'needs_review', 'ready'])->exists()) {
                throw ValidationException::withMessages([
                    'correction' => 'Sudah ada pembetulan yang belum disahkan untuk batch ini.',
                ]);
            }

            $time = now();
            $batch = FfbImportBatch::create([
                'mill_id' => $predecessor->mill_id,
                'receipt_date' => $predecessor->receipt_date,
                'uploaded_by' => $user->id,
                'receipt_type' => 'zero_receipt',
                'status' => 'ready',
                'ticket_count' => 0,
                'parsed_total_mt' => '0.000000',
                'unresolved_count' => 0,
                'has_complete_weight_total' => true,
                'validation_summary' => [
                    'errors' => [], 'warnings' => [], 'declaration' => 'zero_receipt_correction',
                ],
                'validated_at' => $time,
                'preview_hash' => hash('sha256', implode('|', [
                    'zero-correction', $predecessor->id, $user->id, $reason, $time->toISOString(),
                ])),
                'confirmation_reason' => $reason,
                'supersedes_batch_id' => $predecessor->id,
            ]);
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'ffb_correction_candidate_created',
                'model_type' => class_basename($batch),
                'model_id' => $batch->id,
                'old_values' => null,
                'new_values' => [
                    'batch_id' => $batch->id,
                    'supersedes_batch_id' => $predecessor->id,
                    'mill_id' => $batch->mill_id,
                    'receipt_date' => $batch->receipt_date->toDateString(),
                    'receipt_type' => 'zero_receipt',
                    'correction_reason' => $reason,
                    'created_by' => ['id' => $user->id, 'name' => $user->name],
                ],
                'ip_address' => request()->ip(),
            ]);

            return $batch;
        });
    }

    public function history(FfbImportBatch $batch): array
    {
        return FfbImportBatch::with(['uploader', 'confirmer'])
            ->where('mill_id', $batch->mill_id)
            ->whereDate('receipt_date', $batch->receipt_date)
            ->where(function ($query): void {
                $query->whereIn('status', ['confirmed', 'superseded'])
                    ->orWhereNotNull('supersedes_batch_id');
            })
            ->orderBy('id')
            ->get()
            ->values()
            ->map(fn (FfbImportBatch $version, int $index): array => [
                'batch' => $version,
                'version' => $index + 1,
                'reason' => $version->confirmation_reason,
                'is_parallel_run' => app(FfbDailyReceiptService::class)
                    ->isParallelRun($version->receipt_date->toDateString()),
            ])->all();
    }

    public function comparison(FfbImportBatch $before, FfbImportBatch $after): array
    {
        $beforeTickets = $before->tickets()->with('weighbridgeCentre.ppk')->orderBy('id')->get();
        $afterTickets = $after->tickets()->with('weighbridgeCentre.ppk')->orderBy('id')->get();
        $oldByNumber = $beforeTickets->keyBy(fn ($ticket): string => strtoupper(trim((string) $ticket->ticket_number)));
        $newByNumber = $afterTickets->keyBy(fn ($ticket): string => strtoupper(trim((string) $ticket->ticket_number)));
        $added = [];
        $removed = [];
        $changed = [];

        foreach ($newByNumber as $number => $ticket) {
            if ($number === '') {
                continue;
            }
            if (! $oldByNumber->has($number)) {
                $added[] = $number;
                continue;
            }
            $old = $oldByNumber->get($number);
            if ((string) $old->weight_mt !== (string) $ticket->weight_mt
                || $old->source_category !== $ticket->source_category
                || $old->ppk_id !== $ticket->ppk_id
                || $old->weighbridge_centre_id !== $ticket->weighbridge_centre_id) {
                $changed[] = $number;
            }
        }
        foreach ($oldByNumber as $number => $ticket) {
            if ($number !== '' && ! $newByNumber->has($number)) {
                $removed[] = $number;
            }
        }

        return [
            'before' => $this->totals($before, $beforeTickets),
            'after' => $this->totals($after, $afterTickets),
            'tickets' => [
                'added' => $added,
                'removed' => $removed,
                'changed' => $changed,
            ],
        ];
    }

    public function assertActivePredecessor(FfbImportBatch $predecessor): void
    {
        if ($predecessor->status !== 'confirmed'
            || $predecessor->receipt_date->toDateString() < '2026-11-05'
            || $predecessor->receipt_date->toDateString() > now('Asia/Kuala_Lumpur')->toDateString()) {
            throw ValidationException::withMessages([
                'correction' => 'Hanya batch disahkan aktif mulai 05/11/2026 boleh dibetulkan.',
            ]);
        }

        if (FfbImportBatch::where('mill_id', $predecessor->mill_id)
            ->whereDate('receipt_date', $predecessor->receipt_date)
            ->where('status', 'confirmed')
            ->whereKeyNot($predecessor->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'correction' => 'Terdapat lebih daripada satu batch aktif. Pembetulan dihentikan untuk semakan Admin.',
            ]);
        }
    }

    private function totals(FfbImportBatch $batch, $tickets): array
    {
        return [
            'batch_id' => $batch->id,
            'receipt_type' => $batch->receipt_type,
            'ticket_count' => $batch->receipt_type === 'zero_receipt' ? 0 : $tickets->count(),
            'total_mt' => $batch->receipt_type === 'zero_receipt'
                ? '0.00' : (string) ($batch->confirmed_daily_total_mt ?? $batch->parsed_total_mt),
            'categories' => $tickets->groupBy('source_category')->map(fn ($items): array => [
                'ticket_count' => $items->count(),
                'total_mt' => number_format($items->sum(fn ($ticket): float => (float) $ticket->weight_mt), 6, '.', ''),
            ])->all(),
            'ppk' => $tickets->groupBy(fn ($ticket): string => $ticket->weighbridgeCentre?->ppk?->code
                ?? ($ticket->ppk_id !== null ? 'PPK #'.$ticket->ppk_id : 'Tiada PPK'))
                ->map(fn ($items): array => [
                    'ticket_count' => $items->count(),
                    'total_mt' => number_format($items->sum(fn ($ticket): float => (float) $ticket->weight_mt), 6, '.', ''),
                ])->all(),
        ];
    }
}
