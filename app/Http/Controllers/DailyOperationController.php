<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DailyOperation;
use App\Models\Mill;
use App\Services\DailyOperationRecalculationService;
use App\Services\DailyReportNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DailyOperationController extends Controller
{
    /**
     * Redirect ringkas - guna 'records' untuk senarai, 'create' untuk input baru
     */
    public function index()
    {
        return redirect()->route('data-harian.create');
    }

    /**
     * 2. Input Data Harian - form
     */
    public function create(Request $request, DailyOperationRecalculationService $recalculationService)
    {
        $user = $request->user();
        $mills = $user->isMillScopedRole() ? Mill::where('id', $user->mill_id)->get() : Mill::where('is_active', true)->get();

        $selectedMillId = $user->isMillScopedRole() ? $user->mill_id : $request->input('mill_id');
        $selectedTarikh = $request->input('tarikh', now()->toDateString());

        $opening = $recalculationService->resolveOpeningBalance($selectedMillId ? (int) $selectedMillId : null, $selectedTarikh);

        return view('data-harian.create', [
            'mills' => $mills,
            'selectedMillId' => $selectedMillId,
            'selectedTarikh' => $selectedTarikh,
            'defaultBakiSemalam' => $opening['baki_bts_semalam'],
            'defaultStokCpoYesterday' => $opening['stok_cpo_yesterday'],
            'defaultStokPkYesterday' => $opening['stok_pk_yesterday'],
            'canEditBakiBtsSemalam' => $opening['can_edit_baki_bts_semalam'],
            'canEditStokCpoYesterday' => $opening['can_edit_stok_cpo_yesterday'],
            'canEditStokPkYesterday' => $opening['can_edit_stok_pk_yesterday'],
        ]);
    }

   public function store(
    Request $request,
    DailyOperationRecalculationService $recalculationService,
    DailyReportNotificationService $notificationService
)
    {
        $user = $request->user();

        $validated = $this->validateData($request, $user);
        $validated['operation_status'] = $this->normalizeOperationStatus($validated['operation_status'] ?? null);
        $validated['shift'] = $validated['shift'] ?? 'Harian';

        $validated = $recalculationService->prepareForPersistence($validated);

        $data = $validated;
        $data['officer_id'] = $user->id;
        $data['status'] = 'submitted';

        $operation = DailyOperation::create($data);

        $recalculationService->recalculateFromDate(
            (int) $operation->mill_id,
            Carbon::parse($operation->tarikh)
        );

        AuditLog::record('created', $operation, null, $operation->toArray());
        $notificationService->sendIfReady($operation->tarikh);

        return redirect()->route('rekod-harian.index')->with('success', 'Rekod harian berjaya disimpan.');
    }

    /**
     * 3. Senarai Rekod Harian (semua role boleh lihat, difilter ikut role)
     */
    public function records(Request $request)
    {
        $user = $request->user();
        $mills = Mill::where('is_active', true)->get();

        $query = DailyOperation::with(['mill', 'officer'])->orderByDesc('tarikh');

        if ($user->isMillScopedRole()) {
            $query->where('mill_id', $user->mill_id);
        } elseif ($request->filled('mill_id')) {
            $query->where('mill_id', $request->input('mill_id'));
        }

        if ($request->filled('tarikh_mula')) {
            $query->where('tarikh', '>=', $request->input('tarikh_mula'));
        }
        if ($request->filled('tarikh_akhir')) {
            $query->where('tarikh', '<=', $request->input('tarikh_akhir'));
        }
        if ($request->filled('bulan')) {
            $query->whereMonth('tarikh', $request->input('bulan'));
        }
        if ($request->filled('tahun')) {
            $query->whereYear('tarikh', $request->input('tahun'));
        }

        $records = $query->paginate(20)->withQueryString();

        return view('data-harian.records', compact('records', 'mills'));
    }

    public function show(DailyOperation $daily_operation, Request $request)
    {
        $user = $request->user();
        if ($user->isMillScopedRole() && $daily_operation->mill_id !== $user->mill_id) {
            abort(403);
        }

        $daily_operation->load(['mill', 'officer', 'downtimeLogs', 'lastAmendedBy']);

        return view('data-harian.show', compact('daily_operation'));
    }

    public function edit(DailyOperation $daily_operation, Request $request, DailyOperationRecalculationService $recalculationService)
    {
        $user = $request->user();

        if (! $user->canEditData()) {
            abort(403);
        }

        $authorization = Gate::inspect('update', $daily_operation);
        if ($authorization->denied()) {
            return redirect()->route('rekod-harian.index')->with('error', $authorization->message() ?: 'Akses kemaskini rekod ditolak.');
        }

        $mills = $user->isMillScopedRole() ? Mill::where('id', $user->mill_id)->get() : Mill::where('is_active', true)->get();

        $opening = $recalculationService->resolveOpeningBalance(
            $daily_operation->mill_id,
            $daily_operation->tarikh->toDateString(),
            $daily_operation->id
        );

        return view('data-harian.edit', compact('daily_operation', 'mills') + [
            'canEditBakiBtsSemalam' => $opening['can_edit_baki_bts_semalam'],
            'canEditStokCpoYesterday' => $opening['can_edit_stok_cpo_yesterday'],
            'canEditStokPkYesterday' => $opening['can_edit_stok_pk_yesterday'],
        ]);
    }

    public function update(DailyOperation $daily_operation, Request $request, DailyOperationRecalculationService $recalculationService)
    {
        $user = $request->user();

        if (! $user->canEditData()) {
            abort(403);
        }

        $authorization = Gate::inspect('update', $daily_operation);
        if ($authorization->denied()) {
            return redirect()->route('rekod-harian.index')->with('error', $authorization->message() ?: 'Akses kemaskini rekod ditolak.');
        }

        $validated = $this->validateData($request, $user, $daily_operation->id, $daily_operation->shift, true);
        $validated['operation_status'] = $this->normalizeOperationStatus($validated['operation_status'] ?? null);
        $validated['shift'] = $validated['shift'] ?? $daily_operation->shift ?? 'Harian';

        $amendmentReason = $validated['amendment_reason'];
        unset($validated['amendment_reason']);

        $validated = $recalculationService->prepareForPersistence($validated, $daily_operation->id);

        $result = DB::transaction(function () use ($daily_operation, $validated, $amendmentReason, $recalculationService, $user): array {
            $oldMillId = (int) $daily_operation->mill_id;
            $oldTarikh = $daily_operation->tarikh->copy();
            $oldValues = $daily_operation->toArray();

            $daily_operation->update(array_merge($validated, [
                'last_amendment_reason' => $amendmentReason,
                'last_amended_by' => $user->id,
                'last_amended_at' => now(),
            ]));

            $subsequentRecalculated = $recalculationService->recalculateFromDate(
                (int) $daily_operation->mill_id,
                Carbon::parse($daily_operation->tarikh)
            );

            if ($oldMillId !== (int) $daily_operation->mill_id || ! $oldTarikh->isSameDay($daily_operation->tarikh)) {
                $subsequentRecalculated += $recalculationService->recalculateFromDate($oldMillId, $oldTarikh);
            }

            $daily_operation->refresh();

            $changedFields = [];
            foreach ($validated as $field => $newValue) {
                $oldValue = $oldValues[$field] ?? null;
                if ((string) $oldValue !== (string) $newValue) {
                    $changedFields[] = $field;
                }
            }

            $auditOld = [
                'record' => $oldValues,
                'changed_fields' => $changedFields,
            ];

            $auditNew = [
                'record' => $daily_operation->toArray(),
                'summary' => [
                    'description' => sprintf(
                        'Rekod harian %s bagi %s dipinda oleh %s. Sistem mengira semula %d rekod berikutnya.',
                        $daily_operation->mill->code ?? 'N/A',
                        $daily_operation->tarikh->translatedFormat('d F Y'),
                        $user->role->label ?? 'Pengguna',
                        $subsequentRecalculated
                    ),
                    'amendment_reason' => $amendmentReason,
                    'changed_fields' => $changedFields,
                    'changed_field_count' => count($changedFields),
                    'recalculated_subsequent_records' => $subsequentRecalculated,
                    'amended_by' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'role' => $user->role?->label,
                    ],
                    'mill' => [
                        'id' => $daily_operation->mill_id,
                        'name' => $daily_operation->mill?->name,
                    ],
                    'daily_operation_id' => $daily_operation->id,
                    'operation_date' => $daily_operation->tarikh->toDateString(),
                    'amended_at' => now()->toDateTimeString(),
                ],
            ];

            AuditLog::record('amended_with_recalculation', $daily_operation, $auditOld, $auditNew);

            return [
                'subsequent_recalculated' => $subsequentRecalculated,
            ];
        });

        $message = 'Rekod harian berjaya dipinda dan data selepas tarikh tersebut telah dikira semula.';
        if (($result['subsequent_recalculated'] ?? 0) > 0) {
            $message = sprintf(
                'Rekod harian berjaya dipinda. %d rekod selepasnya telah dikira semula.',
                $result['subsequent_recalculated']
            );
        }

        return redirect()->route('rekod-harian.index')->with('success', $message);
    }

    public function destroy(DailyOperation $daily_operation)
    {
        AuditLog::record('deleted', $daily_operation, $daily_operation->toArray(), null);

        $daily_operation->delete();

        return redirect()->route('rekod-harian.index')->with('success', 'Data harian berjaya dipadam.');
    }

    /**
     * Senarai rekod yang BELUM diisi data kualiti (untuk T+1 key-in)
     */
    public function qualityPending(Request $request)
    {
        $user = $request->user();
        $query = DailyOperation::with('mill')
            ->where(function ($query) {
                $query->whereNull('ffa')
                      ->orWhereNull('moisture')
                      ->orWhereNull('dirt');
            })
            ->orderByDesc('tarikh');

        if ($user->isMillScopedRole()) {
            $query->where('mill_id', $user->mill_id);
        }

        $records = $query->paginate(20);

        return view('data-harian.quality-pending', compact('records'));
    }

    /**
     * Form kemaskini kualiti untuk satu rekod (T+1)
     */
    public function editQuality(DailyOperation $daily_operation, Request $request)
    {
        $user = $request->user();
        if ($user->isMillScopedRole() && $daily_operation->mill_id !== $user->mill_id) {
            abort(403);
        }

        return view('data-harian.edit-quality', compact('daily_operation'));
    }

    public function updateQuality(DailyOperation $daily_operation, Request $request)
    {
        $user = $request->user();
        if ($user->isMillScopedRole() && $daily_operation->mill_id !== $user->mill_id) {
            abort(403);
        }

        $validated = $request->validate([
            'ffa' => ['required', 'numeric', 'min:0', 'max:100'],
            'moisture' => ['required', 'numeric', 'min:0', 'max:100'],
            'dirt' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $daily_operation->load('mill');
        $validated['throughput'] = $daily_operation->computeThroughput();
        $validated['utilisation_rate'] = $daily_operation->computeUtilisationRate($validated['throughput']);

        $old = $daily_operation->only(array_keys($validated));
        $daily_operation->update($validated);

        AuditLog::record('quality_updated', $daily_operation, $old, $validated);

        return redirect()->route('data-harian.quality-pending')->with('success', 'Data kualiti berjaya dikemaskini.');
    }

    /**
     * Validation rules pusat - ikut keperluan validation dalam spesifikasi:
     * - Tidak boleh duplicate tarikh+kilang+shift
     * - BTS diproses tak boleh > BTS diterima + baki stok
     * - Downtime tak boleh > 24 jam
     * - Semua field penting wajib
     */
    private function validateData(Request $request, $user, ?int $ignoreId = null, ?string $existingShift = null, bool $isUpdate = false): array
    {
        $millRule = $user->isMillScopedRole() ? Rule::in([$user->mill_id]) : 'exists:mills,id';

        $validated = $request->validate([
            'tarikh' => ['required', 'date', 'before_or_equal:today'],
            'mill_id' => ['required', $millRule],
            'operation_status' => ['required', Rule::in(['Operasi', 'Tidak Operasi (Terima Buah Sahaja)'])],

            'bts_diterima' => ['required', 'numeric', 'min:0'],
            'bts_diproses' => ['required', 'numeric', 'min:0'],
            'jam_operasi' => ['required', 'numeric', 'min:0', 'max:24'],
            'downtime_jam' => ['required', 'numeric', 'min:0', 'max:24'],
            'sebab_downtime' => ['nullable', 'string'],

            'pengeluaran_cpo' => ['required', 'numeric', 'min:0'],
            'pengeluaran_pk' => ['required', 'numeric', 'min:0'],
            'pk_kcp_to_hopper' => [Rule::requiredIf(fn () => $this->isBukitBujangMillId((int) $request->input('mill_id'))), 'numeric', 'min:0'],
            'produksi_cpo' => ['nullable', 'numeric', 'min:0'],
            'produksi_pk' => ['nullable', 'numeric', 'min:0'],
            'stok_cpo_yesterday' => ['nullable', 'numeric', 'min:0'],
            'stok_pk_yesterday' => ['nullable', 'numeric', 'min:0'],
            'stok_cpo' => ['required', 'numeric', 'min:0'],
            'stok_pk' => ['required', 'numeric', 'min:0'],
            'baki_bts_semalam' => ['nullable', 'numeric', 'min:0'],
            'baki_bts_selepas_diproses' => ['nullable', 'numeric', 'min:0'],

            'isu_operasi' => ['nullable', 'string'],
            'tindakan_pembetulan' => ['nullable', 'string'],
            'catatan_tambahan' => ['nullable', 'string'],
            'amendment_reason' => [$isUpdate ? 'required' : 'nullable', 'string', 'min:5', 'max:500'],
        ], [
            'mill_id.in' => 'Anda hanya boleh key-in data untuk kilang anda sendiri.',
            'tarikh.before_or_equal' => 'Tarikh operasi tidak boleh melebihi tarikh hari ini.',
            'jam_operasi.max' => 'Jam operasi tidak boleh melebihi 24 jam.',
            'downtime_jam.max' => 'Downtime tidak boleh melebihi 24 jam.',
            'amendment_reason.required' => 'Sebab Pindaan wajib diisi semasa kemaskini rekod.',
        ]);

        $validated['shift'] = $validated['shift'] ?? $request->input('shift') ?? $existingShift ?? 'Harian';

        // Validation custom: elak duplicate tarikh+kilang+shift (kecuali rekod sendiri semasa update)
        $duplicateQuery = DailyOperation::where('tarikh', $validated['tarikh'])
            ->where('mill_id', $validated['mill_id'])
            ->where('shift', $validated['shift']);

        if ($ignoreId) {
            $duplicateQuery->where('id', '!=', $ignoreId);
        }

        if ($duplicateQuery->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'tarikh' => 'Data untuk tarikh, kilang dan shift ini sudah wujud.',
            ]);
        }

        return $validated;
    }

    private function normalizeOperationStatus(?string $value): string
    {
        return $value === 'Tidak Operasi (Terima Buah Sahaja)'
            ? 'Tidak Operasi (Terima Buah Sahaja)'
            : 'Operasi';
    }

    private function isBukitBujangMillId(?int $millId): bool
    {
        if (! $millId) {
            return false;
        }

        return Mill::whereKey($millId)->value('code') === 'BBJ';
    }

}
