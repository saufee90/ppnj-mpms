@extends('layouts.app')
@section('title', 'Laporan')

@section('content')
<form method="GET" action="{{ route('laporan.index') }}" class="bg-white rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3" id="filterForm">
    @if(auth()->user()->canViewAllMills())
    <div>
        <label class="block text-xs text-gray-500 mb-1">Kilang</label>
        <select name="mill_id" class="border rounded-lg px-3 py-2 text-sm">
            <option value="">Gabungan Semua Kilang</option>
            @foreach($mills as $mill)
                <option value="{{ $mill->id }}" {{ request('mill_id') == $mill->id ? 'selected' : '' }}>{{ $mill->name }}</option>
            @endforeach
        </select>
    </div>
    @endif
    @if(isset($operatedDays))
    <div class="w-full text-sm font-semibold text-gray-700">Hari Operasi: {{ $operatedDays }}/{{ $referenceDays }}</div>
    @endif
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tarikh Mula</label>
        <input type="date" name="tarikh_mula" value="{{ request('tarikh_mula') }}" class="border rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tarikh Akhir</label>
        <input type="date" name="tarikh_akhir" value="{{ request('tarikh_akhir') }}" class="border rounded-lg px-3 py-2 text-sm">
    </div>
    <button class="px-4 py-2 rounded-lg ppnj-green text-white text-sm">Jana Laporan</button>
    <a href="{{ route('laporan.export.excel') }}?{{ request()->getQueryString() }}" class="px-4 py-2 rounded-lg border text-sm">⬇ Export Excel (CSV)</a>
    <a href="{{ route('laporan.export.pdf') }}?{{ request()->getQueryString() }}" target="_blank" class="px-4 py-2 rounded-lg border text-sm">🖨 Export PDF</a>
</form>

<div class="bg-white rounded-xl shadow-sm p-4 mb-4">
    <p class="text-sm md:text-base font-semibold text-gray-800">{{ $reportPeriodTitle }}</p>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
                <th class="px-4 py-3 text-left">Tarikh</th>
                <th class="px-4 py-3 text-left">Kilang</th>
                <th class="px-4 py-3 text-right">BTS Diproses</th>
                <th class="px-4 py-3 text-right">Jualan CPO</th>
                <th class="px-4 py-3 text-right">Jualan PK</th>
                <th class="px-4 py-3 text-right">Produksi CPO</th>
                <th class="px-4 py-3 text-right">Produksi PK</th>
                <th class="px-4 py-3 text-right">Stok CPO Semalam</th>
                <th class="px-4 py-3 text-right">Stok PK Semalam</th>
                <th class="px-4 py-3 text-right">OER%</th>
                <th class="px-4 py-3 text-right">KER%</th>
                <th class="px-4 py-3 text-right">Downtime</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse($records as $r)
            <tr>
                <td class="px-4 py-3">{{ $r->tarikh->format('d/m/Y') }}</td>
                <td class="px-4 py-3">{{ $r->mill->name }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($r->bts_diproses,2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($r->pengeluaran_cpo,2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($r->pengeluaran_pk,2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($r->produksi_cpo,2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($r->produksi_pk,2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($r->stok_cpo_yesterday,2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($r->stok_pk_yesterday,2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($r->oer,2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($r->ker,2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($r->downtime_jam,2) }}</td>
            </tr>
            @empty
            <tr><td colspan="10" class="px-4 py-8 text-center text-gray-400">Tiada data untuk tempoh ini.</td></tr>
            @endforelse
        </tbody>
        @if($records->count())
        <tfoot class="bg-gray-50 font-semibold">
            <tr>
                <td class="px-4 py-3" colspan="2">JUMLAH</td>
                <td class="px-4 py-3 text-right">{{ number_format($records->sum('bts_diproses'),2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($records->sum('pengeluaran_cpo'),2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($records->sum('pengeluaran_pk'),2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($records->sum('produksi_cpo'),2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($records->sum('produksi_pk'),2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($records->sum('stok_cpo_yesterday'),2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($records->sum('stok_pk_yesterday'),2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($summaryOer,2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($summaryKer,2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($records->sum('downtime_jam'),2) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>
@endsection
