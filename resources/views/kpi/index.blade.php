@extends('layouts.app')
@section('title', 'Tetapan KPI')

@section('content')

<div class="bg-white rounded-xl shadow-sm p-6 mb-6">
    <h3 class="text-sm font-semibold ppnj-green-text mb-4">Tambah Sasaran KPI Baharu</h3>
    <form method="POST" action="{{ route('kpi.store') }}" class="grid grid-cols-2 md:grid-cols-6 gap-4 items-end">
        @csrf
        <div>
            <label class="block text-xs text-gray-500 mb-1">Kilang</label>
            <select name="mill_id" class="w-full border rounded-lg px-3 py-2 text-sm">
                <option value="">Global (Semua Kilang)</option>
                @foreach($mills as $mill)
                    <option value="{{ $mill->id }}">{{ $mill->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tahun</label>
            <input type="number" name="effective_year" value="{{ now()->year }}" class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">OER Target (%)</label>
            <input type="number" step="0.01" name="oer_target" value="20" class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">KER Target (%)</label>
            <input type="number" step="0.01" name="ker_target" value="5" class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">FFA Max (%)</label>
            <input type="number" step="0.01" name="ffa_max" value="5" class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Downtime Max (jam)</label>
            <input type="number" step="0.01" name="downtime_max_hours" value="2" class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="col-span-2 md:col-span-6">
            <button class="px-5 py-2 rounded-lg ppnj-green text-white text-sm">Tambah Sasaran</button>
        </div>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
                <th class="px-4 py-3 text-left">Kilang</th>
                <th class="px-4 py-3 text-center">Tahun</th>
                <th class="px-4 py-3 text-right">OER Target%</th>
                <th class="px-4 py-3 text-right">KER Target%</th>
                <th class="px-4 py-3 text-right">FFA Max%</th>
                <th class="px-4 py-3 text-right">Downtime Max</th>
                <th class="px-4 py-3 text-center">Status</th>
                <th class="px-4 py-3 text-center">Tindakan</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @foreach($targets as $t)
            <tr>
                <td class="px-4 py-3">{{ $t->mill->name ?? 'Global' }}
                    <form id="kpi-form-{{ $t->id }}" method="POST" action="{{ route('kpi.update', $t) }}" class="hidden">
                        @csrf @method('PUT')
                    </form>
                </td>
                <td class="px-4 py-3 text-center">{{ $t->effective_year }}</td>
                <td class="px-4 py-3 text-right"><input form="kpi-form-{{ $t->id }}" type="number" step="0.01" name="oer_target" value="{{ $t->oer_target }}" class="w-20 border rounded px-2 py-1 text-right"></td>
                <td class="px-4 py-3 text-right"><input form="kpi-form-{{ $t->id }}" type="number" step="0.01" name="ker_target" value="{{ $t->ker_target }}" class="w-20 border rounded px-2 py-1 text-right"></td>
                <td class="px-4 py-3 text-right"><input form="kpi-form-{{ $t->id }}" type="number" step="0.01" name="ffa_max" value="{{ $t->ffa_max }}" class="w-20 border rounded px-2 py-1 text-right"></td>
                <td class="px-4 py-3 text-right"><input form="kpi-form-{{ $t->id }}" type="number" step="0.01" name="downtime_max_hours" value="{{ $t->downtime_max_hours }}" class="w-20 border rounded px-2 py-1 text-right"></td>
                <td class="px-4 py-3 text-center">
                    <select form="kpi-form-{{ $t->id }}" name="is_active" class="border rounded px-2 py-1 text-xs">
                        <option value="1" {{ $t->is_active ? 'selected' : '' }}>Aktif</option>
                        <option value="0" {{ !$t->is_active ? 'selected' : '' }}>Tidak Aktif</option>
                    </select>
                </td>
                <td class="px-4 py-3 text-center"><button form="kpi-form-{{ $t->id }}" class="text-blue-600 hover:underline">Simpan</button></td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
