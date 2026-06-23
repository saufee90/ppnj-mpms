@extends('layouts.app')
@section('title', 'Perbandingan Kilang')

@section('content')
<form method="GET" class="bg-white rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tahun</label>
        <select name="tahun" class="border rounded-lg px-3 py-2 text-sm" onchange="this.form.submit()">
            @for($y=now()->year;$y>=now()->year-3;$y--)
                <option value="{{ $y }}" {{ (string)$year === (string)$y ? 'selected' : '' }}>{{ $y }}</option>
            @endfor
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Bulan (pilihan)</label>
        <select name="bulan" class="border rounded-lg px-3 py-2 text-sm" onchange="this.form.submit()">
            <option value="">Semua Bulan</option>
            @for($m=1;$m<=12;$m++)
                <option value="{{ $m }}" {{ (string)$month === (string)$m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>
            @endfor
        </select>
    </div>
</form>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-600 mb-3">BTS Diproses vs CPO</p>
        <canvas id="chartProd"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-600 mb-3">OER vs KER (%)</p>
        <canvas id="chartKpi"></canvas>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
                <th class="px-4 py-3 text-left">Kilang</th>
                <th class="px-4 py-3 text-right">BTS Diproses</th>
                <th class="px-4 py-3 text-right">CPO</th>
                <th class="px-4 py-3 text-right">PK</th>
                <th class="px-4 py-3 text-right">OER%</th>
                <th class="px-4 py-3 text-right">KER%</th>
                <th class="px-4 py-3 text-right">FFA%</th>
                <th class="px-4 py-3 text-right">Downtime</th>
                <th class="px-4 py-3 text-right">Utilisation%</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @foreach($comparison as $row)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium">{{ $row['mill'] }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['bts_diproses'],2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['cpo'],2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['pk'],2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['oer'],2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['ker'],2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($row['ffa'],2) }}</td>
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
new Chart(document.getElementById('chartProd'), {
    type: 'bar',
    data: {
        labels: @json($comparison->pluck('mill')),
        datasets: [
            { label: 'BTS Diproses (MT)', data: @json($comparison->pluck('bts_diproses')), backgroundColor: '#0B5D32' },
            { label: 'CPO (MT)', data: @json($comparison->pluck('cpo')), backgroundColor: '#C9A227' },
        ]
    }
});
new Chart(document.getElementById('chartKpi'), {
    type: 'bar',
    data: {
        labels: @json($comparison->pluck('mill')),
        datasets: [
            { label: 'OER (%)', data: @json($comparison->pluck('oer')), backgroundColor: '#0B5D32' },
            { label: 'KER (%)', data: @json($comparison->pluck('ker')), backgroundColor: '#C9A227' },
        ]
    }
});
</script>
@endsection
