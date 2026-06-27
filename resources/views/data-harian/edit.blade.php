@extends('layouts.app')
@section('title', 'Kemaskini Data Harian')

@section('content')
<div class="bg-white rounded-xl shadow-sm p-6 max-w-4xl">
    <form method="POST" action="{{ route('data-harian.update', $daily_operation) }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">A. Maklumat Asas</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tarikh *</label>
                    <input type="date" name="tarikh" value="{{ old('tarikh', $daily_operation->tarikh->toDateString()) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Kilang *</label>
                    <select name="mill_id" required class="w-full border rounded-lg px-3 py-2 text-sm">
                        @foreach($mills as $mill)
                            <option value="{{ $mill->id }}" {{ old('mill_id', $daily_operation->mill_id) == $mill->id ? 'selected' : '' }}>{{ $mill->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">B. Penerimaan & Pemprosesan</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">BTS Diterima (MT) *</label>
                    <input type="number" step="0.01" name="bts_diterima" value="{{ old('bts_diterima', $daily_operation->bts_diterima) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">BTS Diproses (MT) *</label>
                    <input type="number" step="0.01" name="bts_diproses" value="{{ old('bts_diproses', $daily_operation->bts_diproses) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Baki BTS Semalam (MT)</label>
                    <input type="number" step="0.01" name="baki_bts_semalam" value="{{ old('baki_bts_semalam', $daily_operation->baki_bts_semalam) }}" @readonly(!($canEditOpeningBalance ?? false)) class="w-full border rounded-lg px-3 py-2 text-sm {{ !($canEditOpeningBalance ?? false) ? 'bg-gray-100' : '' }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Baki BTS Selepas Diproses (MT)</label>
                    <input type="number" step="0.01" name="baki_bts_selepas_diproses" value="{{ old('baki_bts_selepas_diproses', $daily_operation->baki_bts_selepas_diproses) }}" readonly class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-100">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jam Operasi Kilang *</label>
                    <input type="number" step="0.01" name="jam_operasi" value="{{ old('jam_operasi', $daily_operation->jam_operasi) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Downtime (jam) *</label>
                    <input type="number" step="0.01" name="downtime_jam" value="{{ old('downtime_jam', $daily_operation->downtime_jam) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-xs text-gray-500 mb-1">Sebab Downtime</label>
                <textarea name="sebab_downtime" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('sebab_downtime', $daily_operation->sebab_downtime) }}</textarea>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">C. Pengeluaran</h3>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jualan CPO (MT) *</label>
                    <input type="number" step="0.01" name="pengeluaran_cpo" value="{{ old('pengeluaran_cpo', $daily_operation->pengeluaran_cpo) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jualan PK (MT) *</label>
                    <input type="number" step="0.01" name="pengeluaran_pk" value="{{ old('pengeluaran_pk', $daily_operation->pengeluaran_pk) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Stok CPO Semalam (MT)</label>
                    <input type="number" step="0.01" name="stok_cpo_yesterday" value="{{ old('stok_cpo_yesterday', $daily_operation->stok_cpo_yesterday) }}" @readonly(!($canEditOpeningBalance ?? false)) class="w-full border rounded-lg px-3 py-2 text-sm {{ !($canEditOpeningBalance ?? false) ? 'bg-gray-100' : '' }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Stok PK Semalam (MT)</label>
                    <input type="number" step="0.01" name="stok_pk_yesterday" value="{{ old('stok_pk_yesterday', $daily_operation->stok_pk_yesterday) }}" @readonly(!($canEditOpeningBalance ?? false)) class="w-full border rounded-lg px-3 py-2 text-sm {{ !($canEditOpeningBalance ?? false) ? 'bg-gray-100' : '' }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Pengeluaran CPO (MT)</label>
                    <input type="number" step="0.01" name="produksi_cpo" value="{{ old('produksi_cpo', $daily_operation->produksi_cpo) }}" readonly class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-100">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Pengeluaran PK (MT)</label>
                    <input type="number" step="0.01" name="produksi_pk" value="{{ old('produksi_pk', $daily_operation->produksi_pk) }}" readonly class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-100">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Stok CPO Semasa (MT) *</label>
                    <input type="number" step="0.01" name="stok_cpo" value="{{ old('stok_cpo', $daily_operation->stok_cpo) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Stok PK Semasa (MT) *</label>
                    <input type="number" step="0.01" name="stok_pk" value="{{ old('stok_pk', $daily_operation->stok_pk) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">* OER dan KER dikira automatik berdasarkan produksi dan BTS diproses. FFA, Moisture dan Dirt masih perlu diisi melalui menu <strong>"Kemaskini Kualiti"</strong>.</p>
        </div>

        <div class="p-4 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 text-sm">
            ℹ️ Data kualiti dikemaskini melalui menu <strong>"Kemaskini Kualiti"</strong>.
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">E. Catatan</h3>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Isu Operasi</label>
                    <textarea name="isu_operasi" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('isu_operasi', $daily_operation->isu_operasi) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tindakan Pembetulan</label>
                    <textarea name="tindakan_pembetulan" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('tindakan_pembetulan', $daily_operation->tindakan_pembetulan) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Catatan Tambahan</label>
                    <textarea name="catatan_tambahan" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('catatan_tambahan', $daily_operation->catatan_tambahan) }}</textarea>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-2 border-t">
            <a href="{{ route('rekod-harian.index') }}" class="px-4 py-2 rounded-lg border text-sm">Batal</a>
            <button type="submit" class="px-5 py-2 rounded-lg ppnj-green text-white text-sm font-medium">Kemaskini Data</button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    function parseValue(name) {
        const el = document.querySelector('[name="' + name + '"]');
        if (!el) return 0;
        const val = parseFloat(el.value);
        return Number.isFinite(val) ? val : 0;
    }

    function writeValue(name, value) {
        const el = document.querySelector('[name="' + name + '"]');
        if (!el) return;
        el.value = (Math.round(value * 100) / 100).toFixed(2);
    }

    function recalculateDerivedFields() {
        const produksiCpo = parseValue('stok_cpo') - parseValue('stok_cpo_yesterday') + parseValue('pengeluaran_cpo');
        const produksiPk = parseValue('stok_pk') - parseValue('stok_pk_yesterday') + parseValue('pengeluaran_pk');
        const bakiBts = parseValue('baki_bts_semalam') + parseValue('bts_diterima') - parseValue('bts_diproses');

        writeValue('produksi_cpo', produksiCpo);
        writeValue('produksi_pk', produksiPk);
        writeValue('baki_bts_selepas_diproses', bakiBts);
    }

    ['stok_cpo', 'stok_cpo_yesterday', 'pengeluaran_cpo', 'stok_pk', 'stok_pk_yesterday', 'pengeluaran_pk', 'baki_bts_semalam', 'bts_diterima', 'bts_diproses']
        .forEach(function (name) {
            const el = document.querySelector('[name="' + name + '"]');
            if (el) {
                el.addEventListener('input', recalculateDerivedFields);
            }
        });

    recalculateDerivedFields();
</script>
@endpush
