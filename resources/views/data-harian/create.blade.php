@extends('layouts.app')
@section('title', 'Input Data Harian')

@section('content')
<div class="bg-white rounded-xl shadow-sm p-6 max-w-4xl">
    <form method="POST" action="{{ route('data-harian.store') }}" class="space-y-6">
        @csrf

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">A. Maklumat Asas</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tarikh *</label>
                    <input id="tarikh_input" type="date" name="tarikh" max="{{ now()->toDateString() }}" value="{{ old('tarikh', $selectedTarikh ?? now()->toDateString()) }}" onchange="refreshOpeningBalanceByDate()" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Kilang *</label>
                    <select name="mill_id" required class="w-full border rounded-lg px-3 py-2 text-sm" onchange="refreshOpeningBalance(this.value)">
                        <option value="">Pilih Kilang</option>
                        @foreach($mills as $mill)
                            <option value="{{ $mill->id }}" {{ (string)request('mill_id') === (string)$mill->id || (string)old('mill_id') === (string)$mill->id ? 'selected' : '' }}>{{ $mill->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Status Operasi *</label>
                    <select name="operation_status" required class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="Operasi" {{ old('operation_status', 'Operasi') === 'Operasi' ? 'selected' : '' }}>Operasi</option>
                        <option value="Tidak Operasi (Terima Buah Sahaja)" {{ old('operation_status', 'Operasi') === 'Tidak Operasi (Terima Buah Sahaja)' ? 'selected' : '' }}>Tidak Operasi (Terima Buah Sahaja)</option>
                    </select>
                </div>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">B. Penerimaan & Pemprosesan</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">BTS Diterima (MT) *</label>
                    <input type="number" step="0.01" name="bts_diterima" value="{{ old('bts_diterima', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">BTS Diproses (MT) *</label>
                    <input type="number" step="0.01" name="bts_diproses" value="{{ old('bts_diproses', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Baki BTS Semalam (MT)</label>
                    <input type="number" step="0.01" name="baki_bts_semalam" value="{{ old('baki_bts_semalam', $defaultBakiSemalam) }}" @readonly(!($canEditOpeningBalance ?? false)) class="w-full border rounded-lg px-3 py-2 text-sm {{ !($canEditOpeningBalance ?? false) ? 'bg-gray-100' : '' }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Baki BTS Selepas Diproses (MT)</label>
                    <input type="number" step="0.01" name="baki_bts_selepas_diproses" value="{{ old('baki_bts_selepas_diproses', 0) }}" readonly class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-100">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jam Operasi Kilang *</label>
                    <input type="number" step="0.01" name="jam_operasi" value="{{ old('jam_operasi', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Downtime (jam) *</label>
                    <input type="number" step="0.01" name="downtime_jam" value="{{ old('downtime_jam', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-xs text-gray-500 mb-1">Sebab Downtime</label>
                <textarea name="sebab_downtime" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('sebab_downtime') }}</textarea>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">C. Pengeluaran</h3>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jualan CPO (MT) *</label>
                    <input type="number" step="0.01" name="pengeluaran_cpo" value="{{ old('pengeluaran_cpo', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Pengeluaran CPO (MT)</label>
                    <input type="number" step="0.01" name="produksi_cpo" value="{{ old('produksi_cpo', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Stok CPO Semalam (MT)</label>
                    <input type="number" step="0.01" name="stok_cpo_yesterday" value="{{ old('stok_cpo_yesterday', $defaultStokCpoYesterday) }}" @readonly(!($canEditOpeningBalance ?? false)) class="w-full border rounded-lg px-3 py-2 text-sm {{ !($canEditOpeningBalance ?? false) ? 'bg-gray-100' : '' }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Stok CPO Semasa (MT) *</label>
                    <input type="number" step="0.01" name="stok_cpo" value="{{ old('stok_cpo', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jualan PK (MT) *</label>
                    <input type="number" step="0.01" name="pengeluaran_pk" value="{{ old('pengeluaran_pk', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Pengeluaran PK (MT)</label>
                    <input type="number" step="0.01" name="produksi_pk" value="{{ old('produksi_pk', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Stok PK Semalam (MT)</label>
                    <input type="number" step="0.01" name="stok_pk_yesterday" value="{{ old('stok_pk_yesterday', $defaultStokPkYesterday) }}" @readonly(!($canEditOpeningBalance ?? false)) class="w-full border rounded-lg px-3 py-2 text-sm {{ !($canEditOpeningBalance ?? false) ? 'bg-gray-100' : '' }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Stok PK Semasa (MT) *</label>
                    <input type="number" step="0.01" name="stok_pk" value="{{ old('stok_pk', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">* OER dan KER dikira automatik berdasarkan produksi dan BTS diproses. FFA, Moisture dan Dirt masih perlu diisi melalui menu <strong>"Kemaskini Kualiti"</strong>.</p>
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">D. Kualiti</h3>
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">OER (%)</label>
                    <input type="number" step="0.01" name="oer" value="{{ old('oer', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">KER (%)</label>
                    <input type="number" step="0.01" name="ker" value="{{ old('ker', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">FFA (%)</label>
                    <input type="number" step="0.01" name="ffa" value="{{ old('ffa', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Moisture (%)</label>
                    <input type="number" step="0.01" name="moisture" value="{{ old('moisture', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Dirt (%)</label>
                    <input type="number" step="0.01" name="dirt" value="{{ old('dirt', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
            </div>
        </div>

        <div class="p-4 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 text-sm">
            ℹ️ Data kualiti (FFA, Moisture dan Dirt) akan dikemas kini melalui menu <strong>"Kemaskini Kualiti"</strong> selepas keputusan makmal diterima. Nilai OER, KER, Throughput dan Utilisation dikira secara automatik oleh sistem.
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">E. Catatan</h3>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Isu Operasi</label>
                    <textarea name="isu_operasi" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('isu_operasi') }}</textarea>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tindakan Pembetulan</label>
                    <textarea name="tindakan_pembetulan" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('tindakan_pembetulan') }}</textarea>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Catatan Tambahan</label>
                    <textarea name="catatan_tambahan" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('catatan_tambahan') }}</textarea>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-2 border-t">
            <a href="{{ route('rekod-harian.index') }}" class="px-4 py-2 rounded-lg border text-sm">Batal</a>
            <button type="submit" class="px-5 py-2 rounded-lg ppnj-green text-white text-sm font-medium">Simpan Data</button>
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

    function setQualityFieldState(isNonOperasi) {
        ['oer', 'ker', 'ffa', 'moisture', 'dirt'].forEach(function (name) {
            const el = document.querySelector('[name="' + name + '"]');
            if (!el) return;

            if (isNonOperasi) {
                writeValue(name, 0);
                el.readOnly = true;
                el.classList.add('bg-gray-100');
            } else {
                el.readOnly = false;
                el.classList.remove('bg-gray-100');
            }
        });
    }

    function recalculateDerivedFields() {
        const isNonOperasi = (document.querySelector('[name="operation_status"]')?.value || '') === 'Tidak Operasi (Terima Buah Sahaja)';
        setQualityFieldState(isNonOperasi);

        if (isNonOperasi) {
            writeValue('bts_diproses', 0);
            writeValue('jam_operasi', 0);
            writeValue('downtime_jam', 0);

            const bakiBtsNonOperasi = parseValue('baki_bts_semalam') + parseValue('bts_diterima');
            const produksiCpoNonOperasi = parseValue('stok_cpo') - parseValue('stok_cpo_yesterday') + parseValue('pengeluaran_cpo');
            const produksiPkNonOperasi = parseValue('stok_pk') - parseValue('stok_pk_yesterday') + parseValue('pengeluaran_pk');
            writeValue('baki_bts_selepas_diproses', bakiBtsNonOperasi);
            writeValue('produksi_cpo', produksiCpoNonOperasi);
            writeValue('produksi_pk', produksiPkNonOperasi);

            return;
        }

        const produksiCpo = parseValue('stok_cpo') - parseValue('stok_cpo_yesterday') + parseValue('pengeluaran_cpo');
        const produksiPk = parseValue('stok_pk') - parseValue('stok_pk_yesterday') + parseValue('pengeluaran_pk');
        const bakiBts = parseValue('baki_bts_semalam') + parseValue('bts_diterima') - parseValue('bts_diproses');

        writeValue('produksi_cpo', produksiCpo);
        writeValue('produksi_pk', produksiPk);
        writeValue('baki_bts_selepas_diproses', bakiBts);
    }

    function refreshOpeningBalance(millId) {
        const tarikh = document.getElementById('tarikh_input')?.value || '';
        const params = new URLSearchParams();

        if (millId) {
            params.set('mill_id', millId);
        }
        if (tarikh) {
            params.set('tarikh', tarikh);
        }

        window.location.href = '{{ route('data-harian.create') }}' + (params.toString() ? ('?' + params.toString()) : '');
    }

    function refreshOpeningBalanceByDate() {
        const millId = document.querySelector('[name="mill_id"]')?.value || '';
        refreshOpeningBalance(millId);
    }

    ['operation_status', 'stok_cpo', 'stok_cpo_yesterday', 'pengeluaran_cpo', 'stok_pk', 'stok_pk_yesterday', 'pengeluaran_pk', 'baki_bts_semalam', 'bts_diterima', 'bts_diproses']
        .forEach(function (name) {
            const el = document.querySelector('[name="' + name + '"]');
            if (el) {
                el.addEventListener(name === 'operation_status' ? 'change' : 'input', recalculateDerivedFields);
            }
        });

    recalculateDerivedFields();
</script>
@endpush
