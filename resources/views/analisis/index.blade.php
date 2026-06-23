@extends('layouts.app')
@section('title', 'Analisis Prestasi')

@section('content')
<form method="GET" class="bg-white rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
    @if(!auth()->user()->isPegawaiKilang())
    <div>
        <label class="block text-xs text-gray-500 mb-1">Kilang</label>
        <select name="mill_id" class="border rounded-lg px-3 py-2 text-sm" onchange="this.form.submit()">
            <option value="">Semua Kilang</option>
            @foreach($mills as $mill)
                <option value="{{ $mill->id }}" {{ (string)$millId === (string)$mill->id ? 'selected' : '' }}>{{ $mill->name }}</option>
            @endforeach
        </select>
    </div>
    @endif
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tahun</label>
        <select name="tahun" class="border rounded-lg px-3 py-2 text-sm" onchange="this.form.submit()">
            @for($y=now()->year;$y>=now()->year-3;$y--)
                <option value="{{ $y }}" {{ (string)$year === (string)$y ? 'selected' : '' }}>{{ $y }}</option>
            @endfor
        </select>
    </div>
</form>

<div class="bg-white rounded-xl shadow-sm p-4 mb-6">
    <p class="text-sm font-semibold text-gray-600 mb-3">Trend Prestasi Bulanan {{ $year }}</p>
    <canvas id="chartMonthly"></canvas>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
                <th class="px-4 py-3 text-left">Bulan</th>
                <th class="px-4 py-3 text-right">BTS Diproses</th>
                <th class="px-4 py-3 text-right">CPO</th>
                <th class="px-4 py-3 text-right">PK</th>
                <th class="px-4 py-3 text-right">OER%</th>
                <th class="px-4 py-3 text-right">KER%</th>
                <th class="px-4 py-3 text-right">Downtime</th>
                <th class="px-4 py-3 text-right">Utilisation%</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @foreach($monthlyStats as $row)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">{{ $row['bulan'] }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['bts_diproses'],2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['cpo'],2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['pk'],2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['oer'],2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['ker'],2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['downtime'],2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['utilisation'],2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection

@section('scripts')
<script>
new Chart(document.getElementById('chartMonthly'), {
    type: 'bar',
    data: {
        labels: @json($monthlyStats->pluck('bulan')),
        datasets: [
            { label: 'BTS Diproses (MT)', data: @json($monthlyStats->pluck('bts_diproses')), backgroundColor: '#0B5D32' },
            { label: 'CPO (MT)', data: @json($monthlyStats->pluck('cpo')), backgroundColor: '#C9A227' },
        ]
    },
    options: { responsive: true }
});
</script>
@endsection
