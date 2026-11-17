<?php

namespace App\Services;

use App\Models\DailyOperation;
use App\Models\FfbImportBatch;
use App\Models\Mill;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FfbDailyReceiptService
{
    public const EFFECTIVE_DATE = '2026-12-01';
    public const PARALLEL_RUN_START = '2026-11-05';
    public const PARALLEL_RUN_END = '2026-11-30';
    public const CLOSED = 'Tidak Operasi (Tutup Sepenuhnya)';
    public const STATUSES = ['Operasi', 'Tidak Operasi (Terima Buah Sahaja)', self::CLOSED];

    public function applies(string $date): bool
    {
        return $date >= $this->effectiveDate();
    }

    public function effectiveDate(): string
    {
        return self::EFFECTIVE_DATE;
    }

    public function isParallelRun(string $date): bool
    {
        return $date >= self::PARALLEL_RUN_START && $date <= self::PARALLEL_RUN_END;
    }

    public function confirmed(int $millId, string $date, bool $lock = false): ?FfbImportBatch
    {
        return FfbImportBatch::where('mill_id', $millId)->whereDate('receipt_date', $date)
            ->where('status', 'confirmed')->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public function lockActor(int $millId, User $actor): User
    {
        $mill = Mill::whereKey($millId)->lockForUpdate()->firstOrFail();
        $user = User::with('role')->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($user->is_active && $user->canEditData()
            && ($user->isAdmin() || (int) $user->mill_id === $millId), 403);
        abort_unless($mill->is_active, 403, 'Kilang tidak aktif.');

        return $user;
    }

    public function prepare(Request $request, ?DailyOperation $operation = null): ?FfbImportBatch
    {
        $identity = $request->validate([
            'mill_id' => ['required', 'integer', 'exists:mills,id'],
            'tarikh' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Kuala_Lumpur')->toDateString()],
            'shift' => ['sometimes', 'in:Harian,Shift 1,Shift 2,Shift 3'],
            'ffb_import_batch_id' => ['prohibited'],
        ]);
        $millId = (int) $identity['mill_id'];
        $date = $identity['tarikh'];
        if ($operation?->ffb_import_batch_id) {
            if ($millId !== (int) $operation->mill_id || $date !== $operation->tarikh->toDateString()
                || $request->input('shift', $operation->shift) !== $operation->shift) {
                throw ValidationException::withMessages(['tarikh' => 'Kilang, tarikh dan shift rekod FFB dikunci.']);
            }
        } elseif ($operation && ($millId !== (int) $operation->mill_id || $date !== $operation->tarikh->toDateString())) {
            if ($this->applies($date) || $this->applies($operation->tarikh->toDateString())
                || $this->confirmed($millId, $date)) {
                throw ValidationException::withMessages(['tarikh' => 'Rekod sejarah tidak boleh dipindahkan ke aliran FFB baharu.']);
            }
        }
        if (! $this->applies($date) && $request->input('operation_status') === self::CLOSED) {
            throw ValidationException::withMessages(['operation_status' => 'Status tutup sepenuhnya memerlukan pengesahan FFB mulai '.$this->effectiveDate().'.']);
        }

        $batch = ($this->applies($date) || $operation?->ffb_import_batch_id)
            ? $this->confirmed($millId, $date, true)
            : null;
        if (! $this->applies($date) && ! $operation?->ffb_import_batch_id) {
            return null;
        }
        if ($request->input('shift', 'Harian') !== 'Harian') {
            throw ValidationException::withMessages(['shift' => 'Penerimaan FFB hanya menyokong satu rekod Harian.']);
        }
        if (! $batch) {
            throw ValidationException::withMessages(['tarikh' => 'Sahkan penerimaan BTS atau tiada penerimaan di FFB Receive terlebih dahulu.']);
        }
        if ($operation && (int) $operation->ffb_import_batch_id !== $batch->id) {
            throw ValidationException::withMessages(['tarikh' => 'Rekod sedia ada tidak boleh ditindih atau dipautkan secara retroaktif.']);
        }
        if (! $operation && ($batch->daily_operation_id !== null
            || DailyOperation::where('mill_id', $millId)->whereDate('tarikh', $date)->exists())) {
            throw ValidationException::withMessages(['tarikh' => 'Rekod harian bagi kilang dan tarikh ini sudah wujud.']);
        }
        $request->merge(['bts_diterima' => $batch->confirmed_daily_total_mt, 'shift' => 'Harian']);

        return $batch;
    }

    public function validateClosed(array $data): void
    {
        if (($data['operation_status'] ?? null) !== self::CLOSED) {
            return;
        }
        foreach (['bts_diterima', 'bts_diproses', 'jam_operasi', 'downtime_jam',
            'pengeluaran_cpo', 'pengeluaran_pk', 'pk_kcp_to_hopper'] as $field) {
            if ((float) ($data[$field] ?? 0) !== 0.0) {
                throw ValidationException::withMessages([$field => 'Hari tutup sepenuhnya memerlukan penerimaan dan aktiviti sifar.']);
            }
        }
    }
}
