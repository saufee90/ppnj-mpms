<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FfbImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Persist an unconfirmed Excel candidate; confirmed mill/dates reject reimport.
 * "ready" means no technical blockers, not independently reconciled or approved.
 */
class FfbReceiveImportService
{
    public function __construct(private FfbExcelTicketParser $parser)
    {
    }

    public function import(
        UploadedFile $file,
        User $uploader,
        array $input,
        ?FfbImportBatch $predecessor = null,
        ?string $correctionReason = null,
    ): FfbImportBatch
    {
        if ($predecessor && (mb_strlen(trim((string) $correctionReason)) < 5
            || mb_strlen(trim((string) $correctionReason)) > 500)) {
            throw ValidationException::withMessages([
                'correction_reason' => 'Sebab pembetulan wajib diisi antara 5 hingga 500 aksara.',
            ]);
        }

        try {
            $format = IOFactory::identify($file->getPathname(), ['Xls', 'Xlsx']);
        } catch (SpreadsheetException $exception) {
            throw ValidationException::withMessages([
                'workbook' => 'Kandungan fail mesti merupakan buku kerja Excel XLS atau XLSX yang sah.',
            ]);
        }

        if ($format === 'Xlsx') {
            $this->validateArchive($file->getPathname());
        }

        $disk = Storage::disk('ffb_receive');
        $path = $disk->putFileAs('workbooks', $file, Str::uuid().'.'.strtolower($format));
        if ($path === false) {
            throw new RuntimeException('FFB workbook could not be stored privately.');
        }

        try {
            $batch = DB::transaction(function () use (
                $file, $format, $path, $uploader, $input, $predecessor, $correctionReason
            ): FfbImportBatch {
                $actor = app(FfbDailyReceiptService::class)->lockActor((int) $input['mill_id'], $uploader);
                $previous = null;
                if ($predecessor) {
                    $previous = FfbImportBatch::whereKey($predecessor->id)->lockForUpdate()->firstOrFail();
                    Gate::forUser($actor)->authorize('correct', $previous);
                    if ($previous->status !== 'confirmed'
                        || $previous->mill_id !== (int) $input['mill_id']
                        || $previous->receipt_date->toDateString() !== $input['receipt_date']) {
                        throw ValidationException::withMessages([
                            'receipt_date' => 'Batch terdahulu tidak lagi aktif atau kilang/tarikh pembetulan tidak sepadan.',
                        ]);
                    }
                    if (FfbImportBatch::where('supersedes_batch_id', $previous->id)
                        ->whereIn('status', ['uploaded', 'needs_review', 'ready'])->exists()) {
                        throw ValidationException::withMessages([
                            'correction' => 'Sudah ada pembetulan yang belum disahkan untuk batch ini.',
                        ]);
                    }
                } elseif (FfbImportBatch::where('mill_id', $input['mill_id'])
                    ->whereDate('receipt_date', $input['receipt_date'])
                    ->where('status', 'confirmed')->exists()) {
                    throw ValidationException::withMessages([
                        'receipt_date' => 'Penerimaan telah disahkan dan dikunci. Import semula tidak dibenarkan.',
                    ]);
                }
                $batch = FfbImportBatch::create([
                    'mill_id' => $input['mill_id'],
                    'receipt_date' => $input['receipt_date'],
                    'uploaded_by' => $actor->id,
                    'original_filename' => $file->getClientOriginalName(),
                    'storage_disk' => 'ffb_receive',
                    'storage_path' => $path,
                    'file_sha256' => hash_file('sha256', $file->getPathname()),
                    'file_size_bytes' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'detected_format' => $format,
                    'confirmed_weight_unit' => $input['confirmed_weight_unit'] ?? null,
                    'control_ticket_count' => $input['control_ticket_count'] ?? null,
                    'control_total_mt' => $input['control_total_mt'] ?? null,
                    'supersedes_batch_id' => $previous?->id,
                    'confirmation_reason' => $previous ? trim((string) $correctionReason) : null,
                    'parser_version' => 'sha256:'.hash_file('sha256', __DIR__.DIRECTORY_SEPARATOR.'FfbExcelTicketParser.php'),
                    'resolver_version' => 'sha256:'.hash_file('sha256', __DIR__.DIRECTORY_SEPARATOR.'FfbSourceResolver.php'),
                ]);
                AuditLog::record('ffb_uploaded', $batch, null, [
                    'status' => 'uploaded', 'file_sha256' => $batch->file_sha256,
                    'mill_id' => $batch->mill_id, 'receipt_date' => $input['receipt_date'],
                ]);

                return $batch;
            });
        } catch (Throwable $exception) {
            if (! $disk->delete($path)) {
                report(new RuntimeException('Failed to remove orphaned FFB workbook: '.$path));
            }
            throw $exception;
        }

        try {
            $result = $this->parser->parse(
                $disk->path($path),
                $batch->confirmed_weight_unit,
                $batch->mill->code,
            );
            $this->persistPreview($batch, $result);
        } catch (Throwable $exception) {
            report($exception);
            DB::transaction(function () use ($batch): void {
                $batch->refresh();
                $batch->update([
                    'status' => 'failed',
                    'validation_summary' => [
                        'errors' => ['Import tidak dapat diselesaikan. Sila semak fail atau hubungi Admin dengan ID batch.'],
                    ],
                    'validated_at' => null, 'preview_hash' => null,
                ]);
                AuditLog::record('ffb_import_failed', $batch, ['status' => 'uploaded'], ['status' => 'failed']);
            });
        }

        return $batch->refresh();
    }

    private function persistPreview(FfbImportBatch $batch, FfbTicketParseResult $result): void
    {
        DB::transaction(function () use ($batch, $result): void {
            \App\Models\Mill::whereKey($batch->mill_id)->lockForUpdate()->firstOrFail();
            $batch = FfbImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'uploaded') {
                throw new RuntimeException('FFB batch is no longer an uploaded candidate.');
            }
            $unresolved = 0;
            $totalMicros = 0;
            $complete = true;
            $fingerprints = [];
            foreach ($result->tickets as $ticket) {
                $resolution = $ticket['source_resolution'];
                $reasons = $ticket['review_reasons'];
                if ($ticket['ticket_date'] !== null && $ticket['ticket_date'] !== $batch->receipt_date->toDateString()) {
                    $reasons[] = 'ticket_date_differs_from_receipt_date';
                }
                if ($ticket['ticket_date'] !== null && $ticket['ticket_date'] < '1000-01-01') {
                    $ticket['ticket_date'] = null;
                    $reasons[] = 'ticket_date_out_of_database_range';
                }
                foreach (['ticket_number', 'do_number', 'vehicle_number'] as $field) {
                    if (mb_strlen($ticket[$field]) > 255) {
                        $ticket[$field] = null;
                        $reasons[] = $field.'_exceeds_255_characters';
                    }
                }
                if ($ticket['weight_mt'] !== null && $ticket['weight_mt'] > 999999999.999999) {
                    $ticket['weight_mt'] = null;
                    $reasons[] = 'weight_exceeds_database_range';
                }
                $micros = $ticket['weight_mt'] === null ? null : (int) round($ticket['weight_mt'] * 1000000);
                if ($micros === 0) {
                    $micros = null;
                    $reasons[] = 'weight_below_storage_precision';
                }
                $complete = $complete && $micros !== null;
                $totalMicros += $micros ?? 0;
                $reasons = array_values(array_unique($reasons));
                $unresolved += $reasons !== [] ? 1 : 0;

                $snapshot = [
                    'original_heading' => $resolution->originalHeading,
                    'original_code' => $resolution->originalCode,
                    'requires_review' => $resolution->requiresReview,
                    'review_reason' => $resolution->reviewReason,
                    'group' => $resolution->group?->only(['id', 'heading', 'normalized_heading', 'source_category']),
                    'source' => $resolution->source?->only(['id', 'code', 'name', 'source_category', 'ppk_id']),
                    'ppk' => $resolution->ppk?->only(['id', 'code', 'name', 'entity_type']),
                    'source_category' => $resolution->sourceCategory,
                ];
                $attributes = [
                    'worksheet' => $ticket['worksheet'], 'row_number' => $ticket['row'],
                    'heading_row_number' => $ticket['heading_row'],
                    'original_heading' => $ticket['original_heading'],
                    'original_source_code' => $ticket['source_code'],
                    'ticket_number' => $ticket['ticket_number'],
                    'normalized_ticket_number' => $ticket['ticket_number'] === null
                        ? null : strtoupper(trim($ticket['ticket_number'])),
                    'ticket_date' => $ticket['ticket_date'], 'do_number' => $ticket['do_number'],
                    'vehicle_number' => $ticket['vehicle_number'], 'raw_values' => $ticket['raw_values'],
                    'weight_unit' => $ticket['weight_unit'], 'weight_unit_origin' => $ticket['weight_unit_origin'],
                    'weight_mt' => $micros === null ? null : sprintf('%.6F', $micros / 1000000),
                    'ffb_source_group_id' => $resolution->group?->id,
                    'weighbridge_centre_id' => $resolution->source?->id,
                    'ppk_id' => $resolution->ppk?->id,
                    'source_category' => $resolution->sourceCategory,
                    'resolution_snapshot' => $snapshot,
                    'requires_review' => $reasons !== [], 'review_reasons' => $reasons,
                ];
                $batch->tickets()->create($attributes);
                $fingerprints[] = $attributes;
            }

            $errors = [];
            $warnings = [
                'Jumlah termasuk calon tiket pendua dan baris semakan; berat tidak sah tidak dijumlahkan.',
                'Kilang destinasi berdasarkan pilihan pengguna; nama fail bukan bukti kilang.',
                'Subtotal dan grand total buku kerja tidak digunakan sebagai kawalan bebas oleh parser.',
            ];
            if ($result->ticketCount === 0) {
                $errors[] = 'Tiada calon tiket ditemui.';
            }
            $total = $totalMicros / 1000000;
            if ($total > 999999999.999999) {
                $errors[] = 'Jumlah MT melebihi julat penyimpanan batch.';
            }
            if (round($total, 2) >= 100000000) {
                $errors[] = 'Jumlah MT melebihi julat BTS Diterima rekod harian.';
            }
            $countDifference = $batch->control_ticket_count === null ? null
                : $result->ticketCount - $batch->control_ticket_count;
            $weightDifference = $batch->control_total_mt === null ? null
                : $totalMicros - (int) round((float) $batch->control_total_mt * 1000000);
            if ($countDifference !== null && $countDifference !== 0) {
                $errors[] = 'Bilangan tiket tidak sepadan dengan kawalan pengguna.';
            }
            if ($weightDifference !== null && ($weightDifference !== 0 || ! $complete)) {
                $errors[] = 'Jumlah MT tidak sepadan atau tidak lengkap berbanding kawalan pengguna.';
            }
            if ($countDifference === null || $weightDifference === null) {
                $warnings[] = 'Kawalan bebas tidak lengkap; ready hanya bermaksud pratonton teknikal tanpa isu penghalang.';
            }
            if (FfbImportBatch::where('mill_id', $batch->mill_id)->where('file_sha256', $batch->file_sha256)
                ->whereKeyNot($batch->id)->exists()) {
                $warnings[] = 'Fail yang sama pernah dimuat naik untuk kilang ini. Batch baharu tidak menggantikan batch terdahulu.';
            }
            $status = $unresolved > 0 || $errors !== [] || ! $complete ? 'needs_review' : 'ready';
            $batch->update([
                'status' => $status, 'ticket_count' => $result->ticketCount,
                'parsed_total_mt' => $total <= 999999999.999999 ? sprintf('%.6F', $total) : null,
                'has_complete_weight_total' => $complete, 'unresolved_count' => $unresolved,
                'validation_summary' => [
                    'errors' => $errors, 'warnings' => $warnings,
                    'parser_issues' => $result->issues, 'source_groups' => $result->sourceGroups,
                ],
                'reconciliation_evidence' => [
                    'control_origin' => 'uploader', 'ticket_count_difference' => $countDifference,
                    'total_mt_difference' => $weightDifference === null ? null : sprintf('%.6F', $weightDifference / 1000000),
                    'daily_rounded_total_mt' => sprintf('%.2F', $total),
                    'rounding_difference_mt' => sprintf('%.6F', round($total, 2) - $total),
                ],
                'validated_at' => now(),
                'preview_hash' => hash('sha256', json_encode([
                    'mill_id' => $batch->mill_id, 'receipt_date' => $batch->receipt_date->toDateString(),
                    'file_sha256' => $batch->file_sha256, 'tickets' => $fingerprints,
                    'control_count' => $batch->control_ticket_count, 'control_total' => $batch->control_total_mt,
                ], JSON_THROW_ON_ERROR)),
            ]);
            AuditLog::record('ffb_previewed', $batch, ['status' => 'uploaded'], [
                'status' => $status, 'ticket_count' => $batch->ticket_count,
                'total_mt' => $batch->parsed_total_mt, 'unresolved_count' => $unresolved,
                'preview_hash' => $batch->preview_hash,
            ]);
        });
    }

    private function validateArchive(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['workbook' => 'Arkib XLSX tidak sah.']);
        }
        try {
            $expandedBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false) {
                    throw ValidationException::withMessages(['workbook' => 'Arkib XLSX tidak dapat dibaca.']);
                }
                $expandedBytes += $entry['size'];
                if ($expandedBytes > 100 * 1024 * 1024) {
                    throw ValidationException::withMessages(['workbook' => 'Saiz kandungan XLSX melebihi 100 MB.']);
                }
            }
        } finally {
            $zip->close();
        }
    }
}
