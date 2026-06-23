<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DailyOperation;
use App\Models\Mill;
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
        $mills = $user->isPegawaiKilang() ? Mill::where('id', $user->mill_id)->get() : Mill::where('is_active', true)->get();

        return view('data-harian.create', compact('mills'));
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $this->validateData($request, $user);

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

        if ($user->isPegawaiKilang()) {
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
        if ($user->isPegawaiKilang() && $daily_operation->mill_id !== $user->mill_id) {
            abort(403);
        }

        $daily_operation->load(['mill', 'officer', 'downtimeLogs']);

        return view('data-harian.show', compact('daily_operation'));
    }

    public function edit(DailyOperation $daily_operation, Request $request)
    {
        $user = $request->user();
        $mills = $user->isPegawaiKilang() ? Mill::where('id', $user->mill_id)->get() : Mill::where('is_active', true)->get();

        return view('data-harian.edit', compact('daily_operation', 'mills'));
    }

    public function update(DailyOperation $daily_operation, Request $request)
    {
        $user = $request->user();

        $validated = $this->validateData($request, $user, $daily_operation->id);

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
     * Validation rules pusat - ikut keperluan validation dalam spesifikasi:
     * - Tidak boleh duplicate tarikh+kilang+shift
     * - BTS diproses tak boleh > BTS diterima + baki stok
     * - Downtime tak boleh > 24 jam
     * - Semua field penting wajib
     */
    private function validateData(Request $request, $user, ?int $ignoreId = null): array
    {
        $millRule = $user->isPegawaiKilang() ? Rule::in([$user->mill_id]) : 'exists:mills,id';

        $validated = $request->validate([
            'tarikh' => ['required', 'date'],
            'mill_id' => ['required', $millRule],
            'shift' => ['required', Rule::in(['Shift 1', 'Shift 2', 'Shift 3', 'Harian'])],

            'bts_diterima' => ['required', 'numeric', 'min:0'],
            'bts_diproses' => ['required', 'numeric', 'min:0'],
            'baki_stok_bts' => ['required', 'numeric', 'min:0'],
            'jam_operasi' => ['required', 'numeric', 'min:0', 'max:24'],
            'downtime_jam' => ['required', 'numeric', 'min:0', 'max:24'],
            'sebab_downtime' => ['nullable', 'string'],

            'pengeluaran_cpo' => ['required', 'numeric', 'min:0'],
            'pengeluaran_pk' => ['required', 'numeric', 'min:0'],
            'stok_cpo' => ['required', 'numeric', 'min:0'],
            'stok_pk' => ['required', 'numeric', 'min:0'],

            'ffa' => ['required', 'numeric', 'min:0', 'max:100'],
            'moisture' => ['required', 'numeric', 'min:0', 'max:100'],
            'dirt' => ['required', 'numeric', 'min:0', 'max:100'],

            'isu_operasi' => ['nullable', 'string'],
            'tindakan_pembetulan' => ['nullable', 'string'],
            'catatan_tambahan' => ['nullable', 'string'],
        ], [
            'mill_id.in' => 'Anda hanya boleh key-in data untuk kilang anda sendiri.',
            'jam_operasi.max' => 'Jam operasi tidak boleh melebihi 24 jam.',
            'downtime_jam.max' => 'Downtime tidak boleh melebihi 24 jam.',
        ]);

        // Validation custom: BTS diproses tak boleh lebih dari BTS diterima + baki stok
        if ($validated['bts_diproses'] > ($validated['bts_diterima'] + $validated['baki_stok_bts'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bts_diproses' => 'BTS diproses tidak boleh lebih tinggi daripada BTS diterima + baki stok BTS.',
            ]);
        }

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
}
