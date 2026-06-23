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
                    <input type="date" name="tarikh" value="{{ old('tarikh', now()->toDateString()) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Kilang *</label>
                    <select name="mill_id" required class="w-full border rounded-lg px-3 py-2 text-sm">
                        @foreach($mills as $mill)
                            <option value="{{ $mill->id }}" {{ old('mill_id') == $mill->id ? 'selected' : '' }}>{{ $mill->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Shift / Sesi *</label>
                    <select name="shift" required class="w-full border rounded-lg px-3 py-2 text-sm">
                        @foreach(['Harian','Shift 1','Shift 2','Shift 3'] as $shift)
                            <option value="{{ $shift }}" {{ old('shift') == $shift ? 'selected' : '' }}>{{ $shift }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">B. Penerimaan & Pemprosesan</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach([
                    ['bts_diterima','BTS Diterima (MT) *'],
                    ['bts_diproses','BTS Diproses (MT) *'],
                    ['baki_stok_bts','Baki Stok BTS (MT) *'],
                    ['jam_operasi','Jam Operasi Kilang *'],
                    ['downtime_jam','Downtime (jam) *'],
                ] as [$field, $label])
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ $label }}</label>
                    <input type="number" step="0.01" name="{{ $field }}" value="{{ old($field, 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                @endforeach
            </div>
            <div class="mt-4">
                <label class="block text-xs text-gray-500 mb-1">Sebab Downtime</label>
                <textarea name="sebab_downtime" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('sebab_downtime') }}</textarea>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">C. Pengeluaran</h3>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                @foreach([
                    ['pengeluaran_cpo','Pengeluaran CPO (MT) *'],
                    ['pengeluaran_pk','Pengeluaran PK (MT) *'],
                    ['stok_cpo','Stok CPO Semasa (MT) *'],
                    ['stok_pk','Stok PK Semasa (MT) *'],
                ] as [$field, $label])
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ $label }}</label>
                    <input type="number" step="0.01" name="{{ $field }}" value="{{ old($field, 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-400 mt-2">* OER, KER, Throughput dan Utilisation akan dikira secara automatik selepas data disimpan.</p>
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">D. Kualiti</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach([
                    ['ffa','FFA (%) *'],
                    ['moisture','Moisture (%) *'],
                    ['dirt','Dirt (%) *'],
                ] as [$field, $label])
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ $label }}</label>
                    <input type="number" step="0.01" name="{{ $field }}" value="{{ old($field, 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                @endforeach
            </div>
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
