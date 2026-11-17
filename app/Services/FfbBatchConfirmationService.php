<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DailyOperation;
use App\Models\FfbImportBatch;
use App\Models\Mill;
use App\Models\User;
use App\Models\WeighbridgeCentre;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FfbBatchConfirmationService
{
    private const EXISTING_DAILY_OPERATION_MESSAGE =
        'Pengesahan FFB tidak dibenarkan kerana rekod operasi harian bagi kilang dan tarikh ini sudah wujud. Rekod sedia ada tidak boleh dipautkan secara retroaktif.';

    public function zeroSummary(int $millId, string $date): array
    {
        $errors = [];
        if (app(FfbDailyReceiptService::class)->applies($date)
            && DailyOperation::where('mill_id', $millId)->whereDate('tarikh', $date)
            ->lockForUpdate()->first() !== null) {
            $errors[] = self::EXISTING_DAILY_OPERATION_MESSAGE;
        }
        $candidates = FfbImportBatch::where('mill_id', $millId)->whereDate('receipt_date', $date)
            ->whereNotIn('status', ['cancelled', 'superseded'])->orderBy('id')->get();
        foreach ($candidates as $candidate) {
            if ($candidate->status === 'confirmed') {
                $errors[] = 'Penerimaan bagi kilang dan tarikh ini telah disahkan dan dikunci.';
            } elseif ($candidate->receipt_type === 'excel'
                && ((float) $candidate->parsed_total_mt > 0 || ! $candidate->has_complete_weight_total
                    || $candidate->tickets()->exists())) {
                $errors[] = "Batch #{$candidate->id} masih mempunyai tiket, penerimaan atau jumlah belum lengkap. Pengesahan sifar ditolak.";
            }
        }

        return [
            'errors' => $errors,
            'token' => hash('sha256', json_encode([
                'mill_id' => $millId, 'receipt_date' => $date, 'receipt_type' => 'zero_receipt',
                'candidates' => $candidates->map(fn ($batch) => $batch->attributesToArray())->all(),
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    public function confirmZero(int $millId, string $date, User $actor, string $token): FfbImportBatch
    {
        return DB::transaction(function () use ($millId, $date, $actor, $token): FfbImportBatch {
            app(FfbDailyReceiptService::class)->lockActor($millId, $actor);
            $candidate = new FfbImportBatch(['mill_id' => $millId]);
            $candidate->setRelation('mill', Mill::findOrFail($millId));
            Gate::forUser(User::findOrFail($actor->id))->authorize('confirm', $candidate);
            $summary = $this->zeroSummary($millId, $date);
            if ($summary['errors'] || ! hash_equals($summary['token'], $token)) {
                throw ValidationException::withMessages([
                    'confirmation' => $summary['errors'] ?: ['Ringkasan berubah. Sila semak semula sebelum mengesahkan.'],
                ]);
            }
            $time = now();
            $snapshot = [
                'receipt_type' => 'zero_receipt', 'mill_id' => $millId, 'receipt_date' => $date,
                'confirmed_by' => $actor->id, 'confirmed_at' => $time->toDateTimeString(),
                'ticket_count' => 0, 'total_mt' => '0.000000', 'daily_rounded_total_mt' => '0.00',
                'attestation' => 'Saya mengesahkan tiada penerimaan BTS bagi kilang dan tarikh ini. Pengesahan ini muktamad dan dikunci.',
                'confirmation_token' => $token,
            ];
            $batch = FfbImportBatch::create([
                'receipt_type' => 'zero_receipt', 'mill_id' => $millId, 'receipt_date' => $date,
                'uploaded_by' => $actor->id, 'status' => 'confirmed',
                'ticket_count' => 0, 'parsed_total_mt' => '0.000000',
                'unresolved_count' => 0, 'has_complete_weight_total' => true,
                'validation_summary' => ['errors' => [], 'warnings' => [], 'declaration' => 'zero_receipt'],
                'validated_at' => $time, 'preview_hash' => $token,
                'confirmed_by' => $actor->id, 'confirmed_at' => $time,
                'confirmed_daily_total_mt' => '0.00', 'confirmation_snapshot' => $snapshot,
            ]);
            AuditLog::record('ffb_zero_confirmed', $batch, null, $snapshot + ['batch_id' => $batch->id, 'status' => 'confirmed']);

            return $batch;
        });
    }

    public function summary(FfbImportBatch $batch): array
    {
        return $this->assess($batch, $batch->tickets()->with('weighbridgeCentre.ppk')->orderBy('id')->get());
    }

    public function summaryCorrection(FfbImportBatch $batch): array
    {
        $batch = $batch->fresh() ?? $batch;
        $predecessor = $batch->supersedes_batch_id
            ? FfbImportBatch::find($batch->supersedes_batch_id)
            : null;
        if (! $predecessor) {
            return ['errors' => ['Batch pembetulan tidak mempunyai versi terdahulu yang sah.'], 'warnings' => []];
        }

        $comparison = app(FfbBatchCorrectionService::class)->comparison($predecessor, $batch);
        $tickets = $batch->tickets()->with('weighbridgeCentre.ppk')->orderBy('id')->get();
        if ($batch->receipt_type === 'excel') {
            return $this->assess($batch, $tickets, $predecessor) + ['comparison' => $comparison];
        }

        $errors = [];
        if ($batch->status !== 'ready' || $batch->receipt_type !== 'zero_receipt'
            || trim((string) $batch->confirmation_reason) === ''
            || $batch->validated_at === null || $batch->preview_hash === null
            || $batch->ticket_count !== 0 || (float) $batch->parsed_total_mt !== 0.0
            || ! $batch->has_complete_weight_total || $batch->unresolved_count !== 0 || $tickets->isNotEmpty()) {
            $errors[] = 'Pengesahan sifar pembetulan tidak lengkap atau mempunyai tiket.';
        }
        if ($predecessor->status !== 'confirmed'
            || $predecessor->mill_id !== $batch->mill_id
            || $predecessor->receipt_date->toDateString() !== $batch->receipt_date->toDateString()) {
            $errors[] = 'Versi terdahulu bukan batch aktif bagi kilang dan tarikh ini.';
        }
        if (FfbImportBatch::where('mill_id', $batch->mill_id)
            ->whereDate('receipt_date', $batch->receipt_date)
            ->where('status', 'confirmed')->whereKeyNot($predecessor->id)->exists()) {
            $errors[] = 'Batch aktif telah berubah. Sila muat semula sejarah pembetulan.';
        }

        $operation = DailyOperation::where('mill_id', $batch->mill_id)
            ->whereDate('tarikh', $batch->receipt_date)->first();
        if (app(FfbDailyReceiptService::class)->applies($batch->receipt_date->toDateString())) {
            if ($operation && ($operation->ffb_import_batch_id !== $predecessor->id
                || $predecessor->daily_operation_id !== $operation->id)) {
                $errors[] = self::EXISTING_DAILY_OPERATION_MESSAGE;
            } elseif (! $operation && $predecessor->daily_operation_id !== null) {
                $errors[] = 'Pautan Daily Operation terdahulu tidak konsisten. Pembetulan ditolak.';
            }
        }

        $tokenData = [
            'batch' => $batch->attributesToArray(),
            'predecessor' => $predecessor->attributesToArray(),
            'tickets' => $tickets->map(fn ($ticket) => $ticket->attributesToArray())->all(),
            'operation' => $operation?->only([
                'id', 'mill_id', 'tarikh', 'shift', 'ffb_import_batch_id', 'bts_diterima',
                'baki_bts_semalam', 'baki_bts_selepas_diproses',
            ]),
        ];
        $categories = [];
        foreach (WeighbridgeCentre::SOURCE_CATEGORIES as $category) {
            $categories[$category] = ['ticket_count' => 0, 'total_mt' => '0.000000'];
        }

        return [
            'errors' => $errors,
            'warnings' => [],
            'issues' => [],
            'ticket_count' => 0,
            'total_mt' => '0.000000',
            'rounded_total_mt' => '0.00',
            'categories' => $categories,
            'token' => hash('sha256', json_encode($this->canonical($tokenData), JSON_THROW_ON_ERROR)),
            'comparison' => $comparison,
        ];
    }

    public function confirmCorrection(
        int $batchId,
        User $actor,
        string $token,
    ): FfbImportBatch {
        $reference = FfbImportBatch::findOrFail($batchId);

        return DB::transaction(function () use ($batchId, $actor, $token, $reference): FfbImportBatch {
            $user = app(FfbDailyReceiptService::class)->lockActor((int) $reference->mill_id, $actor);
            $ids = array_values(array_unique([$batchId, (int) $reference->supersedes_batch_id]));
            sort($ids);
            $locked = FfbImportBatch::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $batch = $locked->get($batchId);
            $predecessor = $locked->get((int) $reference->supersedes_batch_id);
            if (! $batch || ! $predecessor || $batch->mill_id !== $predecessor->mill_id
                || $batch->receipt_date->toDateString() !== $predecessor->receipt_date->toDateString()) {
                throw ValidationException::withMessages([
                    'correction' => 'Rantaian versi berubah. Sila buka semula ringkasan pembetulan.',
                ]);
            }

            Gate::forUser($user)->authorize('confirmCorrection', $batch);
            app(FfbBatchCorrectionService::class)->assertActivePredecessor($predecessor);

            $tickets = $batch->tickets()->with('weighbridgeCentre.ppk')->orderBy('id')->lockForUpdate()->get();
            $summary = $this->summaryCorrection($batch);
            if ($summary['errors'] !== []) {
                throw ValidationException::withMessages(['correction' => $summary['errors']]);
            }
            if (! hash_equals($summary['token'], $token)) {
                throw ValidationException::withMessages([
                    'correction' => 'Data pembetulan berubah selepas semakan. Sila semak perbandingan terkini.',
                ]);
            }

            if ($batch->receipt_type === 'excel') {
                $disk = Storage::disk('ffb_receive');
                if ($batch->storage_disk !== 'ffb_receive' || ! $disk->exists($batch->storage_path)
                    || ! hash_equals($batch->file_sha256, hash_file('sha256', $disk->path($batch->storage_path)))) {
                    throw ValidationException::withMessages([
                        'correction' => 'Fail Excel pembetulan tiada atau tidak sepadan dengan rekod import.',
                    ]);
                }
            }

            $date = $batch->receipt_date->toDateString();
            $official = app(FfbDailyReceiptService::class)->applies($date);
            $operation = DailyOperation::where('mill_id', $batch->mill_id)
                ->whereDate('tarikh', $date)->lockForUpdate()->first();
            $operations = collect();
            if ($official && $operation) {
                if ($operation->ffb_import_batch_id !== $predecessor->id
                    || $predecessor->daily_operation_id !== $operation->id) {
                    throw ValidationException::withMessages([
                        'correction' => 'Rekod operasi harian tidak dipautkan kepada batch terdahulu. Pembetulan ditolak.',
                    ]);
                }
                $operations = DailyOperation::where('mill_id', $batch->mill_id)
                    ->whereDate('tarikh', '>=', $date)
                    ->orderBy('tarikh')->orderBy('id')->lockForUpdate()->get();
            } elseif ($official && $predecessor->daily_operation_id !== null) {
                throw ValidationException::withMessages([
                    'correction' => 'Pautan Daily Operation terdahulu tidak konsisten. Pembetulan ditolak.',
                ]);
            }

            $previousState = [
                'status' => $predecessor->status,
                'daily_operation_id' => $predecessor->daily_operation_id,
                'confirmed_daily_total_mt' => $predecessor->confirmed_daily_total_mt,
            ];
            $time = now();
            $predecessor->update(['status' => 'superseded']);
            $batch->update([
                'status' => 'confirmed',
                'confirmed_by' => $user->id,
                'confirmed_at' => $time,
                'confirmed_daily_total_mt' => $summary['rounded_total_mt'],
                'confirmation_snapshot' => [
                    'stage' => 3,
                    'receipt_type' => $batch->receipt_type,
                    'batch_id' => $batch->id,
                    'supersedes_batch_id' => $predecessor->id,
                    'mill_id' => $batch->mill_id,
                    'receipt_date' => $date,
                    'confirmed_by' => $user->id,
                    'confirmed_at' => $time->toDateTimeString(),
                    'correction_reason' => $batch->confirmation_reason,
                    'ticket_count' => $summary['ticket_count'],
                    'total_mt' => $summary['total_mt'],
                    'daily_rounded_total_mt' => $summary['rounded_total_mt'],
                    'comparison' => $summary['comparison'],
                    'parallel_run' => ! $official,
                    'confirmation_token' => $token,
                    'attestation' => 'Saya telah menyemak perbandingan sebelum dan selepas serta mengesahkan pembetulan ini.',
                ],
            ]);

            if ($official && $operation) {
                $this->reconcileBtsBalances(
                    $operations,
                    $operation->id,
                    $predecessor,
                    $batch,
                    (float) $summary['rounded_total_mt'],
                    $user,
                    (string) $batch->confirmation_reason,
                );
                $batch->update(['daily_operation_id' => $operation->id, 'synced_at' => now()]);
            }

            $this->recordCorrectionAudit($user, 'ffb_correction_confirmed', $batch, [
                'batch' => $predecessor->only([
                    'id', 'status', 'confirmed_daily_total_mt', 'confirmed_by', 'confirmed_at',
                    'daily_operation_id', 'synced_at',
                ]),
            ], [
                'batch' => $batch->fresh()->only([
                    'id', 'status', 'supersedes_batch_id', 'confirmed_daily_total_mt',
                    'confirmed_by', 'confirmed_at', 'daily_operation_id', 'synced_at',
                ]),
                'correction_reason' => $batch->confirmation_reason,
                'comparison' => $summary['comparison'],
                'parallel_run' => ! $official,
                'actor' => ['id' => $user->id, 'name' => $user->name],
            ]);
            $this->recordCorrectionAudit($user, 'ffb_correction_superseded', $predecessor, $previousState, [
                'status' => 'superseded',
                'superseded_by_batch_id' => $batch->id,
                'correction_reason' => $batch->confirmation_reason,
                'actor' => ['id' => $user->id, 'name' => $user->name],
            ]);

            return $batch->fresh();
        });
    }

    public function confirm(int $batchId, User $actor, string $token): FfbImportBatch
    {
        $reference = FfbImportBatch::findOrFail($batchId);

        return DB::transaction(function () use ($batchId, $actor, $token, $reference): FfbImportBatch {
            // A stable mill lock serializes even two different candidate batches for the same day.
            Mill::whereKey($reference->mill_id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $batch = FfbImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('confirm', $batch);
            if ($batch->mill_id !== $reference->mill_id) {
                throw ValidationException::withMessages(['confirmation' => 'Kilang batch berubah. Sila buka semula ringkasan.']);
            }
            $tickets = $batch->tickets()->with('weighbridgeCentre.ppk')->orderBy('id')->lockForUpdate()->get();
            $summary = $this->assess($batch, $tickets);
            if ($summary['errors'] !== []) {
                throw ValidationException::withMessages(['confirmation' => $summary['errors']]);
            }
            if (! hash_equals($summary['token'], $token)) {
                throw ValidationException::withMessages([
                    'confirmation' => 'Data batch berubah selepas ringkasan dibuka. Sila semak dan sahkan ringkasan terkini.',
                ]);
            }
            $disk = Storage::disk('ffb_receive');
            if ($batch->storage_disk !== 'ffb_receive' || ! $disk->exists($batch->storage_path)
                || ! hash_equals($batch->file_sha256, hash_file('sha256', $disk->path($batch->storage_path)))) {
                throw ValidationException::withMessages([
                    'confirmation' => 'Fail Excel asal tiada atau tidak sepadan dengan rekod import. Pengesahan ditolak.',
                ]);
            }

            $snapshot = [
                'stage' => 1,
                'receipt_type' => 'excel',
                'batch_id' => $batch->id,
                'mill' => $batch->mill->only(['id', 'code', 'name']),
                'receipt_date' => $batch->receipt_date->toDateString(),
                'confirmed_by' => $user->id,
                'confirmed_at' => now()->toDateTimeString(),
                'ticket_count' => $summary['ticket_count'],
                'total_mt' => $summary['total_mt'],
                'daily_rounded_total_mt' => $summary['rounded_total_mt'],
                'categories' => $summary['categories'],
                'validation_summary' => $batch->validation_summary,
                'reconciliation_evidence' => $batch->reconciliation_evidence,
                'control_ticket_count' => $batch->control_ticket_count,
                'control_total_mt' => $batch->control_total_mt,
                'warnings_acknowledged' => $summary['warnings'],
                'preview_hash' => $batch->preview_hash,
                'confirmation_token' => $summary['token'],
                'file_sha256' => $batch->file_sha256,
                'attestation' => 'Saya telah menyemak tiket, jumlah, kategori dan semua amaran serta mengesahkan penerimaan BTS ini. Pengesahan ini muktamad dan dikunci.',
            ];
            $batch->update([
                'status' => 'confirmed',
                'confirmed_by' => $user->id,
                'confirmed_at' => $snapshot['confirmed_at'],
                'confirmed_daily_total_mt' => $summary['rounded_total_mt'],
                'confirmation_snapshot' => $snapshot,
            ]);
            // Audit failure must roll back confirmation, never leave an unaudited confirmed batch.
            AuditLog::record('ffb_confirmed', $batch, ['status' => 'ready'], $snapshot + ['status' => 'confirmed']);

            return $batch;
        });
    }

    private function assess(
        FfbImportBatch $batch,
        Collection $tickets,
        ?FfbImportBatch $predecessor = null,
    ): array
    {
        $errors = [];
        $operation = DailyOperation::where('mill_id', $batch->mill_id)
            ->whereDate('tarikh', $batch->receipt_date)->lockForUpdate()->first();
        $official = app(FfbDailyReceiptService::class)->applies($batch->receipt_date->toDateString());
        if ($official && $operation && (
            $predecessor === null
            || $operation->ffb_import_batch_id !== $predecessor->id
            || $predecessor->daily_operation_id !== $operation->id
        )) {
            $errors[] = self::EXISTING_DAILY_OPERATION_MESSAGE;
        }
        if ($official && ! $operation && $predecessor?->daily_operation_id !== null) {
            $errors[] = 'Pautan Daily Operation terdahulu tidak konsisten. Pembetulan ditolak.';
        }
        $warnings = data_get($batch->validation_summary, 'warnings', []);
        $issues = [];
        $categories = [];
        foreach (WeighbridgeCentre::SOURCE_CATEGORIES as $category) {
            $categories[$category] = ['ticket_count' => 0, 'micros' => 0];
        }
        if ($batch->receipt_type !== 'excel' || $batch->status !== 'ready' || $batch->confirmed_at !== null || $batch->confirmed_by !== null
            || $batch->confirmation_snapshot !== null
            || ($predecessor === null && $batch->supersedes_batch_id !== null)
            || ($predecessor !== null && ($batch->supersedes_batch_id !== $predecessor->id
                || trim((string) $batch->confirmation_reason) === ''
                || $predecessor->status !== 'confirmed'
                || $predecessor->mill_id !== $batch->mill_id
                || $predecessor->receipt_date->toDateString() !== $batch->receipt_date->toDateString()))
            || $batch->daily_operation_id !== null || $batch->synced_at !== null) {
            $errors[] = $predecessor
                ? 'Batch pembetulan bukan calon yang sah atau versi terdahulu telah berubah.'
                : 'Batch bukan import Excel baharu yang layak disahkan. Pengesahan semula tidak dibenarkan.';
        }
        if (! $batch->validated_at || ! $batch->preview_hash || ! $batch->has_complete_weight_total
            || $batch->unresolved_count !== 0 || data_get($batch->validation_summary, 'errors', []) !== []
            || data_get($batch->validation_summary, 'parser_issues', []) !== []) {
            $errors[] = 'Validasi batch belum lengkap atau masih mempunyai isu penghalang.';
        }
        foreach (data_get($batch->validation_summary, 'errors', []) as $error) {
            $errors[] = $error;
        }
        if ($tickets->isEmpty()) {
            $errors[] = 'Tiada tiket sah untuk disahkan.';
        }
        $totalMicros = 0;
        $seen = [];
        foreach ($tickets as $ticket) {
            $reasons = $ticket->review_reasons ?? [];
            $source = $ticket->weighbridgeCentre;
            if ($source && ($reason = $source->ffbGroupingReviewReason()) !== null) {
                $reasons[] = $reason;
            }
            $weight = $this->micros($ticket->weight_mt);
            $number = strtoupper(trim($ticket->ticket_number ?? ''));
            if ($ticket->requires_review || $reasons !== [] || $weight === null || $weight <= 0
                || $number === '' || isset($seen[$number])
                || trim($ticket->original_heading ?? '') === '' || trim($ticket->original_source_code ?? '') === ''
                || trim($ticket->do_number ?? '') === '' || trim($ticket->vehicle_number ?? '') === ''
                || $ticket->ticket_date?->toDateString() !== $batch->receipt_date->toDateString()
                || ! in_array($ticket->weight_unit, ['KG', 'MT'], true)
                || ! in_array($ticket->weight_unit_origin, ['workbook', 'caller'], true)
                || ! isset($categories[$ticket->source_category])
                || $ticket->ffb_source_group_id === null || $ticket->weighbridge_centre_id === null
                || ! $source || ! $source->is_active
                || $source->source_category !== $ticket->source_category
                || $source->ppk_id !== $ticket->ppk_id
                || data_get($ticket->resolution_snapshot, 'requires_review') !== false
                || data_get($ticket->resolution_snapshot, 'source_category') !== $ticket->source_category
                || data_get($ticket->resolution_snapshot, 'source.id') !== $ticket->weighbridge_centre_id
                || data_get($ticket->resolution_snapshot, 'group.id') !== $ticket->ffb_source_group_id
                || ($ticket->source_category === 'ppnj_ppk_integrated'
                    && ($ticket->ppk_id === null || data_get($ticket->resolution_snapshot, 'ppk.id') !== $ticket->ppk_id))) {
                $issues[] = [
                    'ticket_number' => $ticket->ticket_number,
                    'worksheet' => $ticket->worksheet, 'row' => $ticket->row_number,
                    'reasons' => $reasons !== [] ? $reasons : ['ticket_integrity_or_resolution_invalid'],
                ];
            }
            $seen[$number] = true;
            $totalMicros += $weight ?? 0;
            if (isset($categories[$ticket->source_category])) {
                $categories[$ticket->source_category]['ticket_count']++;
                $categories[$ticket->source_category]['micros'] += $weight ?? 0;
            }
        }
        if ($issues !== []) {
            $errors[] = 'Tiket masih mempunyai isu semakan, pendua, berat tidak sah atau sumber tidak lengkap.';
        }
        if ($batch->ticket_count !== $tickets->count() || $this->micros($batch->parsed_total_mt) !== $totalMicros) {
            $errors[] = 'Bilangan tiket atau jumlah MT tidak sepadan dengan rekod import.';
        }
        if (($batch->control_ticket_count !== null && $batch->control_ticket_count !== $tickets->count())
            || ($batch->control_total_mt !== null && $this->micros($batch->control_total_mt) !== $totalMicros)
            || ! in_array(data_get($batch->reconciliation_evidence, 'ticket_count_difference'), [null, 0], true)
            || (data_get($batch->reconciliation_evidence, 'total_mt_difference') !== null
                && $this->micros(data_get($batch->reconciliation_evidence, 'total_mt_difference')) !== 0)) {
            $errors[] = 'Rekonsiliasi bilangan tiket atau jumlah MT gagal.';
        }
        if ($batch->control_ticket_count === null || $batch->control_total_mt === null) {
            $warnings[] = 'Kawalan bebas tidak lengkap. Pengesahan memerlukan semakan pegawai dan pengakuan amaran ini.';
        }
        $cents = intdiv($totalMicros + 5000, 10000);
        if ($totalMicros <= 0 || $cents > 9999999999) {
            $errors[] = 'Jumlah BTS tidak sah atau melebihi julat pengesahan.';
        }
        $activeBatches = FfbImportBatch::where('mill_id', $batch->mill_id)
            ->whereDate('receipt_date', $batch->receipt_date)
            ->where('status', 'confirmed')->whereKeyNot($batch->id);
        if ($predecessor) {
            $activeBatches->whereKeyNot($predecessor->id);
        }
        if ($activeBatches->exists()) {
            $errors[] = 'Batch lain telah disahkan dan dikunci bagi kilang dan tarikh ini. Penggantian tidak dibenarkan.';
        }
        foreach ($categories as &$category) {
            $category['total_mt'] = $this->decimal($category['micros'], 6);
            unset($category['micros']);
        }
        unset($category);

        $tokenData = [
            'batch' => $batch->attributesToArray(),
            'tickets' => $tickets->map(fn ($ticket) => $ticket->attributesToArray())->all(),
        ];
        if ($predecessor) {
            $tokenData['correction'] = [
                'predecessor' => $predecessor->attributesToArray(),
                'operation' => $operation?->only([
                    'id', 'mill_id', 'tarikh', 'shift', 'ffb_import_batch_id',
                    'bts_diterima', 'baki_bts_semalam', 'baki_bts_selepas_diproses',
                ]),
            ];
        }

        return [
            'errors' => $errors, 'warnings' => array_values(array_unique($warnings)),
            'issues' => $issues, 'ticket_count' => $tickets->count(),
            'total_mt' => $this->decimal($totalMicros, 6),
            'rounded_total_mt' => $this->decimal($cents, 2), 'categories' => $categories,
            'token' => hash('sha256', json_encode($this->canonical($tokenData), JSON_THROW_ON_ERROR)),
        ];
    }

    private function reconcileBtsBalances(
        Collection $operations,
        int $primaryOperationId,
        FfbImportBatch $predecessor,
        FfbImportBatch $replacement,
        float $replacementTotal,
        User $actor,
        string $reason,
    ): void {
        $operationDate = $replacement->receipt_date->toDateString();
        $previousRecord = DailyOperation::where('mill_id', $replacement->mill_id)
            ->whereDate('tarikh', '<', $operationDate)
            ->orderByDesc('tarikh')->orderByDesc('id')
            ->lockForUpdate()->first();
        $previousClosing = (float) ($previousRecord?->baki_bts_selepas_diproses ?? 0);
        $currentDate = null;
        $dateOpening = 0.0;

        foreach ($operations as $operation) {
            $rowDate = $operation->tarikh->toDateString();
            if ($currentDate !== $rowDate) {
                $currentDate = $rowDate;
                $dateOpening = $operation->tarikh->day === 1 ? 0.0 : $previousClosing;
            }

            $isPrimary = $operation->id === $primaryOperationId;
            $received = $isPrimary ? $replacementTotal : (float) $operation->bts_diterima;
            $processed = $operation->operation_status === 'Operasi'
                ? (float) $operation->bts_diproses
                : 0.0;
            $closing = round($dateOpening + $received - $processed, 2);
            if ($closing < 0) {
                throw ValidationException::withMessages([
                    'bts_diproses' => sprintf(
                        'Pembetulan menghasilkan baki BTS negatif pada %s. Tiada perubahan disimpan.',
                        $operation->tarikh->format('d/m/Y'),
                    ),
                ]);
            }

            $before = [
                'ffb_import_batch_id' => $operation->ffb_import_batch_id,
                'bts_diterima' => $operation->bts_diterima,
                'baki_bts_semalam' => $operation->baki_bts_semalam,
                'baki_bts_selepas_diproses' => $operation->baki_bts_selepas_diproses,
                'last_amendment_reason' => $operation->last_amendment_reason,
                'last_amended_by' => $operation->last_amended_by,
                'last_amended_at' => $operation->last_amended_at,
            ];
            $changes = [
                'baki_bts_semalam' => number_format($dateOpening, 2, '.', ''),
                'baki_bts_selepas_diproses' => number_format($closing, 2, '.', ''),
                'updated_at' => now(),
            ];
            if ($isPrimary) {
                $changes += [
                    'ffb_import_batch_id' => $replacement->id,
                    'bts_diterima' => number_format($replacementTotal, 2, '.', ''),
                    'last_amendment_reason' => 'Pembetulan FFB: '.$reason,
                    'last_amended_by' => $actor->id,
                    'last_amended_at' => now(),
                ];
            }
            $after = array_intersect_key($changes, $before);
            $changed = false;
            foreach ($after as $field => $value) {
                if ((string) $before[$field] !== (string) $value) {
                    $changed = true;
                    break;
                }
            }
            if ($changed) {
                DB::table('daily_operations')->where('id', $operation->id)->update($changes);
                $operation->refresh();
                $this->recordCorrectionAudit(
                    $actor,
                    $isPrimary ? 'ffb_correction_daily_reconciled' : 'ffb_correction_bts_balance_recalculated',
                    $operation,
                    $before,
                    [
                        'ffb_import_batch_id' => $operation->ffb_import_batch_id,
                        'bts_diterima' => $operation->bts_diterima,
                        'baki_bts_semalam' => $operation->baki_bts_semalam,
                        'baki_bts_selepas_diproses' => $operation->baki_bts_selepas_diproses,
                        'last_amendment_reason' => $operation->last_amendment_reason,
                        'last_amended_by' => $operation->last_amended_by,
                        'last_amended_at' => $operation->last_amended_at,
                        'correction' => [
                            'previous_batch_id' => $predecessor->id,
                            'replacement_batch_id' => $replacement->id,
                            'reason' => $reason,
                            'actor_id' => $actor->id,
                        ],
                    ],
                );
            }

            $previousClosing = $closing;
        }
    }

    private function micros(?string $value): ?int
    {
        if ($value === null || ! preg_match('/^(\d{1,9})(?:\.(\d{1,6}))?$/D', $value, $match)) {
            return null;
        }

        return (int) $match[1] * 1000000 + (int) str_pad($match[2] ?? '', 6, '0');
    }

    private function decimal(int $value, int $places): string
    {
        $scale = 10 ** $places;

        return intdiv($value, $scale).'.'.str_pad((string) ($value % $scale), $places, '0', STR_PAD_LEFT);
    }

    private function canonical(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $values;
    }

    private function recordCorrectionAudit(
        User $actor,
        string $action,
        Model $model,
        ?array $oldValues,
        ?array $newValues,
    ): void {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'model_type' => class_basename($model),
            'model_id' => $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
        ]);
    }
}
