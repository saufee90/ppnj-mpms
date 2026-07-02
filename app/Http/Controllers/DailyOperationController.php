<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DailyOperation;
use App\Models\Mill;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
    public function create(Request $request)
    {
        $user = $request->user();
        $mills = $user->isMillScopedRole() ? Mill::where('id', $user->mill_id)->get() : Mill::where('is_active', true)->get();

        $selectedMillId = $user->isMillScopedRole() ? $user->mill_id : $request->input('mill_id');
        $selectedTarikh = $request->input('tarikh', now()->toDateString());

        $opening = $this->resolveOpeningBalance($selectedMillId ? (int) $selectedMillId : null, $selectedTarikh);

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

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $this->validateData($request, $user);
        $validated['operation_status'] = $this->normalizeOperationStatus($validated['operation_status'] ?? null);
        $validated['shift'] = $validated['shift'] ?? 'Harian';

        $validated = $this->applyOperationStatusRules($validated);

        $validated = $this->applyOpeningBalance($validated);

        $validated['baki_bts_selepas_diproses'] = round(
            $validated['baki_bts_semalam'] + $validated['bts_diterima'] - $validated['bts_diproses'],
            2
        );
        $this->assertNonNegativeBaki($validated['baki_bts_selepas_diproses']);

        if ($validated['operation_status'] === 'Operasi') {
            $validated['produksi_cpo'] = $this->calculateProduction($validated, 'stok_cpo');
            $validated['produksi_pk'] = $this->calculateProduction($validated, 'stok_pk');
        } else {
            $validated['produksi_cpo'] = $this->resolveManualProductionValue($validated, 'produksi_cpo');
            $validated['produksi_pk'] = $this->resolveManualProductionValue($validated, 'produksi_pk');
        }

        $this->assertNonNegativeProduction($validated);

        $validated['oer'] = $this->calculateOer($validated);
        $validated['ker'] = $this->calculateKer($validated);

        if ($validated['operation_status'] !== 'Operasi') {
            $validated['oer'] = 0;
            $validated['ker'] = 0;
            $validated['ffa'] = 0;
            $validated['moisture'] = 0;
            $validated['dirt'] = 0;
            $validated['throughput'] = 0;
            $validated['utilisation_rate'] = 0;
        }

        $data = $validated;
        $data['officer_id'] = $user->id;
        $data['status'] = 'submitted';

        $operation = DailyOperation::create($data);

        AuditLog::record('created', $operation, null, $operation->toArray());

        return redirect()->route('rekod-harian.index')->with('success', 'Data harian berjaya disimpan.');
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

        $daily_operation->load(['mill', 'officer', 'downtimeLogs']);

        return view('data-harian.show', compact('daily_operation'));
    }

    public function edit(DailyOperation $daily_operation, Request $request)
    {
        $user = $request->user();

        if (! $user->canEditData()) {
            abort(403);
        }

        if ($user->isMillScopedRole() && $daily_operation->mill_id !== $user->mill_id) {
            abort(403);
        }

        if ($this->isEditLockedForUser($user, $daily_operation)) {
            return redirect()->back()->with('error', 'Rekod ini telah dikunci dan tidak lagi boleh dikemas kini. Sila hubungi Pentadbir Sistem jika pembetulan diperlukan.');
        }

        $mills = $user->isMillScopedRole() ? Mill::where('id', $user->mill_id)->get() : Mill::where('is_active', true)->get();

        $opening = $this->resolveOpeningBalance(
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

    public function update(DailyOperation $daily_operation, Request $request)
    {
        $user = $request->user();

        if (! $user->canEditData()) {
            abort(403);
        }

        if ($user->isMillScopedRole() && $daily_operation->mill_id !== $user->mill_id) {
            abort(403);
        }

        if ($this->isEditLockedForUser($user, $daily_operation)) {
            return redirect()->back()->with('error', 'Rekod ini telah dikunci dan tidak lagi boleh dikemas kini. Sila hubungi Pentadbir Sistem jika pembetulan diperlukan.');
        }

        $validated = $this->validateData($request, $user, $daily_operation->id, $daily_operation->shift);
        $validated['operation_status'] = $this->normalizeOperationStatus($validated['operation_status'] ?? null);
        $validated['shift'] = $validated['shift'] ?? $daily_operation->shift ?? 'Harian';

        $validated = $this->applyOperationStatusRules($validated);

        $validated = $this->applyOpeningBalance($validated, $daily_operation->id);

        $validated['baki_bts_selepas_diproses'] = round(
            $validated['baki_bts_semalam'] + $validated['bts_diterima'] - $validated['bts_diproses'],
            2
        );
        $this->assertNonNegativeBaki($validated['baki_bts_selepas_diproses']);

        if ($validated['operation_status'] === 'Operasi') {
            $validated['produksi_cpo'] = $this->calculateProduction($validated, 'stok_cpo');
            $validated['produksi_pk'] = $this->calculateProduction($validated, 'stok_pk');
        } else {
            $validated['produksi_cpo'] = $this->resolveManualProductionValue($validated, 'produksi_cpo');
            $validated['produksi_pk'] = $this->resolveManualProductionValue($validated, 'produksi_pk');
        }

        $this->assertNonNegativeProduction($validated);

        $validated['oer'] = $this->calculateOer($validated);
        $validated['ker'] = $this->calculateKer($validated);

        if ($validated['operation_status'] !== 'Operasi') {
            $validated['oer'] = 0;
            $validated['ker'] = 0;
            $validated['ffa'] = 0;
            $validated['moisture'] = 0;
            $validated['dirt'] = 0;
            $validated['throughput'] = 0;
            $validated['utilisation_rate'] = 0;
        }

        $oldValues = $daily_operation->toArray();
        $daily_operation->update($validated);

        AuditLog::record('updated', $daily_operation, $oldValues, $daily_operation->toArray());

        return redirect()->route('rekod-harian.index')->with('success', 'Data harian berjaya dikemaskini.');
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
    private function validateData(Request $request, $user, ?int $ignoreId = null, ?string $existingShift = null): array
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
        ], [
            'mill_id.in' => 'Anda hanya boleh key-in data untuk kilang anda sendiri.',
            'tarikh.before_or_equal' => 'Tarikh operasi tidak boleh melebihi tarikh hari ini.',
            'jam_operasi.max' => 'Jam operasi tidak boleh melebihi 24 jam.',
            'downtime_jam.max' => 'Downtime tidak boleh melebihi 24 jam.',
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

    private function applyOperationStatusRules(array $data): array
    {
        if (($data['operation_status'] ?? 'Operasi') === 'Operasi') {
            return $data;
        }

        $data['bts_diproses'] = 0.0;
        $data['jam_operasi'] = 0.0;
        $data['downtime_jam'] = 0.0;

        return $data;
    }

    private function resolveManualProductionValue(array $data, string $field): float
    {
        return round((float) ($data[$field] ?? 0), 2);
    }

    private function resolveYesterdayStock(array $data, string $field, ?int $millId = null): float
    {
        $millId = $millId ?? $data['mill_id'];
        $tarikh = isset($data['tarikh']) ? Carbon::parse($data['tarikh']) : Carbon::now();

        $previous = DailyOperation::where('mill_id', $millId)
            ->where('tarikh', '<', $tarikh->toDateString())
            ->orderByDesc('tarikh')
            ->orderByDesc('id')
            ->first();

        if (! $previous) {
            return 0.0;
        }

        return match ($field) {
            'stok_cpo' => $previous->stok_cpo ?? 0.0,
            'stok_pk' => $previous->stok_pk ?? 0.0,
            default => 0.0,
        };
    }

    private function calculateProduction(array $data, string $field): float
    {
        $stok = $data[$field] ?? 0;
        $yesterday = $data["{$field}_yesterday"] ?? 0;
        $salesField = $field === 'stok_cpo' ? 'pengeluaran_cpo' : 'pengeluaran_pk';
        $sales = $data[$salesField] ?? 0;

        return round($stok - $yesterday + $sales, 2);
    }

    private function calculateOer(array $data): float
    {
        $btsDiproses = $data['bts_diproses'] ?? 0;
        $produksiCpo = $data['produksi_cpo'] ?? 0;

        if (! $btsDiproses) {
            return 0.0;
        }

        return round(($produksiCpo / $btsDiproses) * 100, 2);
    }

    private function calculateKer(array $data): float
    {
        $btsDiproses = $data['bts_diproses'] ?? 0;
        $produksiPk = $data['produksi_pk'] ?? 0;

        if (! $btsDiproses) {
            return 0.0;
        }

        return round(($produksiPk / $btsDiproses) * 100, 2);
    }

    private function applyOpeningBalance(array $data, ?int $ignoreId = null): array
    {
        $opening = $this->resolveOpeningBalance((int) $data['mill_id'], (string) $data['tarikh'], $ignoreId);

        if ($opening['can_edit_baki_bts_semalam']) {
            $data['baki_bts_semalam'] = round((float) ($data['baki_bts_semalam'] ?? 0), 2);
        } else {
            $data['baki_bts_semalam'] = $opening['baki_bts_semalam'];
        }

        if ($opening['can_edit_stok_cpo_yesterday']) {
            if ($opening['require_manual_stok_cpo_yesterday'] && $data['stok_cpo_yesterday'] === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'stok_cpo_yesterday' => 'Stok CPO Semalam wajib diisi pada hari pertama bulan.',
                ]);
            }

            $data['stok_cpo_yesterday'] = round((float) ($data['stok_cpo_yesterday'] ?? 0), 2);
        } else {
            $data['stok_cpo_yesterday'] = $opening['stok_cpo_yesterday'];
        }

        if ($opening['can_edit_stok_pk_yesterday']) {
            if ($opening['require_manual_stok_pk_yesterday'] && $data['stok_pk_yesterday'] === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'stok_pk_yesterday' => 'Stok PK Semalam wajib diisi pada hari pertama bulan.',
                ]);
            }

            $data['stok_pk_yesterday'] = round((float) ($data['stok_pk_yesterday'] ?? 0), 2);
        } else {
            $data['stok_pk_yesterday'] = $opening['stok_pk_yesterday'];
        }

        return $data;
    }

    private function resolveOpeningBalance(?int $millId, ?string $tarikh, ?int $ignoreId = null): array
    {
        if (! $tarikh) {
            return [
                'can_edit_baki_bts_semalam' => true,
                'can_edit_stok_cpo_yesterday' => true,
                'can_edit_stok_pk_yesterday' => true,
                'require_manual_stok_cpo_yesterday' => false,
                'require_manual_stok_pk_yesterday' => false,
                'baki_bts_semalam' => 0.0,
                'stok_cpo_yesterday' => null,
                'stok_pk_yesterday' => null,
            ];
        }

        $selectedDate = Carbon::parse($tarikh);
        $isFirstDayOfMonth = $selectedDate->day === 1;

        if (! $millId) {
            return [
                'can_edit_baki_bts_semalam' => ! $isFirstDayOfMonth,
                'can_edit_stok_cpo_yesterday' => $isFirstDayOfMonth,
                'can_edit_stok_pk_yesterday' => $isFirstDayOfMonth,
                'require_manual_stok_cpo_yesterday' => $isFirstDayOfMonth,
                'require_manual_stok_pk_yesterday' => $isFirstDayOfMonth,
                'baki_bts_semalam' => $isFirstDayOfMonth ? 0.0 : 0.0,
                'stok_cpo_yesterday' => $isFirstDayOfMonth ? null : 0.0,
                'stok_pk_yesterday' => $isFirstDayOfMonth ? null : 0.0,
            ];
        }

        $previousQuery = DailyOperation::where('mill_id', $millId)
            ->where('tarikh', '<', $selectedDate->toDateString())
            ->orderByDesc('tarikh')
            ->orderByDesc('id');

        $previousMonthBakiQuery = DailyOperation::where('mill_id', $millId)
            ->whereYear('tarikh', $selectedDate->year)
            ->whereMonth('tarikh', $selectedDate->month)
            ->where('tarikh', '<', $selectedDate->toDateString())
            ->orderByDesc('tarikh')
            ->orderByDesc('id');

        if ($ignoreId) {
            $previousQuery->where('id', '!=', $ignoreId);
            $previousMonthBakiQuery->where('id', '!=', $ignoreId);
        }

        $previous = $previousQuery->first();
        $previousMonthBaki = $previousMonthBakiQuery->first();

        $bakiBtsSemalam = $isFirstDayOfMonth
            ? 0.0
            : round((float) ($previousMonthBaki->baki_bts_selepas_diproses ?? 0), 2);

        if ($isFirstDayOfMonth) {
            return [
                'can_edit_baki_bts_semalam' => false,
                'can_edit_stok_cpo_yesterday' => true,
                'can_edit_stok_pk_yesterday' => true,
                'require_manual_stok_cpo_yesterday' => true,
                'require_manual_stok_pk_yesterday' => true,
                'baki_bts_semalam' => $bakiBtsSemalam,
                'stok_cpo_yesterday' => null,
                'stok_pk_yesterday' => null,
            ];
        }

        return [
            'can_edit_baki_bts_semalam' => false,
            'can_edit_stok_cpo_yesterday' => false,
            'can_edit_stok_pk_yesterday' => false,
            'require_manual_stok_cpo_yesterday' => false,
            'require_manual_stok_pk_yesterday' => false,
            'baki_bts_semalam' => $bakiBtsSemalam,
            'stok_cpo_yesterday' => round((float) ($previous->stok_cpo ?? 0), 2),
            'stok_pk_yesterday' => round((float) ($previous->stok_pk ?? 0), 2),
        ];
    }

    private function assertNonNegativeBaki(float $bakiBtsSelepasDiproses): void
    {
        if ($bakiBtsSelepasDiproses < 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bts_diproses' => 'Baki BTS Selepas Diproses tidak boleh negatif. Sila semak BTS Diproses.',
            ]);
        }
    }

    private function assertNonNegativeProduction(array $data): void
    {
        $produksiCpo = (float) ($data['produksi_cpo'] ?? 0);
        $produksiPk = (float) ($data['produksi_pk'] ?? 0);

        if ($produksiCpo < 0 || $produksiPk < 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'produksi_cpo' => 'Pengeluaran CPO/PK tidak boleh negatif. Sila semak Stok Semalam, Stok Hari Ini dan Jualan.',
                'produksi_pk' => 'Pengeluaran CPO/PK tidak boleh negatif. Sila semak Stok Semalam, Stok Hari Ini dan Jualan.',
            ]);
        }
    }

    private function isEditLockedForUser($user, DailyOperation $dailyOperation): bool
    {
        if ($user->isAdmin()) {
            return false;
        }

        return $dailyOperation->tarikh->lt(now()->subDay()->startOfDay());
    }
}
