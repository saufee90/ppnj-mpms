@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')

<!-- Filter -->
<form method="GET" class="bg-white rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Kilang</label>
        <select name="mill_id" class="border rounded-lg px-3 py-2 text-sm" onchange="this.form.submit()">
            <option value="">Semua Kilang</option>
            @foreach($mills as $mill)
                <option value="{{ $mill->id }}" {{ (string)$selectedMillId === (string)$mill->id ? 'selected' : '' }}>{{ $mill->name }}</option>
            @endforeach
        </select>
    </div>
</form>

<!-- Alert: data belum dihantar -->
@if($millsBelumHantar->count() > 0)
<div class="mb-6 p-4 rounded-xl bg-yellow-50 border border-yellow-200 text-yellow-800 text-sm">
    ⚠️ Data harian belum dihantar untuk: <strong>{{ $millsBelumHantar->implode(', ') }}</strong>
</div>
@endif

<!-- Alert: OER/Downtime/FFA -->
@if($summary['oer'] < $target->oer_target && $summary['bts_diproses'] > 0)
<div class="mb-3 p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm">
    🔻 OER hari ini ({{ number_format($summary['oer'],2) }}%) di bawah sasaran ({{ number_format($target->oer_target,2) }}%)
</div>
@endif
@if($summary['downtime'] > $target->downtime_max_hours)
<div class="mb-6 p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm">
    ⏱️ Downtime hari ini ({{ number_format($summary['downtime'],2) }} jam) melebihi had ({{ number_format($target->downtime_max_hours,2) }} jam)
</div>
@endif

<!-- Kad ringkasan -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    @php
        $cards = [
            ['label' => 'BTS Diterima (MT)', 'value' => number_format($summary['bts_diterima'],2)],
            ['label' => 'BTS Diproses (MT)', 'value' => number_format($summary['bts_diproses'],2)],
            ['label' => 'Pengeluaran CPO (MT)', 'value' => number_format($summary['cpo'],2)],
            ['label' => 'Pengeluaran PK (MT)', 'value' => number_format($summary['pk'],2)],
            ['label' => 'Purata OER (%)', 'value' => number_format($summary['oer'],2)],
            ['label' => 'Purata KER (%)', 'value' => number_format($summary['ker'],2)],
            ['label' => 'Jumlah Downtime (jam)', 'value' => number_format($summary['downtime'],2)],
        ];
    @endphp
    @foreach($cards as $card)
    <div class="bg-white rounded-xl shadow-sm p-4 border-l-4" style="border-color:#C9A227">
        <p class="text-xs text-gray-500">{{ $card['label'] }}</p>
        <p class="text-2xl font-bold ppnj-green-text mt-1">{{ $card['value'] }}</p>
    </div>
    @endforeach
</div>

<!-- MTD / YTD -->
<div class="grid grid-cols-2 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-xs text-gray-500 mb-1">Prestasi Bulanan (MTD)</p>
        <p class="text-lg font-semibold">BTS: {{ number_format($mtdBts,2) }} MT &middot; CPO: {{ number_format($mtdCpo,2) }} MT</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-xs text-gray-500 mb-1">Prestasi Tahunan (YTD)</p>
        <p class="text-lg font-semibold">BTS: {{ number_format($ytdBts,2) }} MT &middot; CPO: {{ number_format($ytdCpo,2) }} MT</p>
    </div>
</div>

<!-- Carta -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-600 mb-3">Trend BTS Diproses Harian (14 hari)</p>
        <canvas id="chartBts"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-600 mb-3">Trend Pengeluaran CPO Harian (14 hari)</p>
        <canvas id="chartCpo"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-600 mb-3">Trend OER vs KER (14 hari)</p>
        <canvas id="chartOerKer"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-600 mb-3">Perbandingan Kilang Hari Ini (BTS Diproses)</p>
        <canvas id="chartComparison"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4 lg:col-span-2">
        <p class="text-sm font-semibold text-gray-600 mb-3">Downtime Mengikut Kilang Hari Ini</p>
        <canvas id="chartDowntime"></canvas>
    </div>
</div>

@endsection

@section('scripts')
<script>
const labels = @json($labels);
const greenColor = '#0B5D32';
const goldColor = '#C9A227';

new Chart(document.getElementById('chartBts'), {
    type: 'line',
    data: { labels, datasets: [{ label: 'BTS Diproses (MT)', data: @json($btsProcessedTrend), borderColor: greenColor, backgroundColor: greenColor+'20', fill: true, tension: 0.3 }] },
    options: { responsive: true }
});

new Chart(document.getElementById('chartCpo'), {
    type: 'line',
    data: { labels, datasets: [{ label: 'CPO (MT)', data: @json($cpoTrend), borderColor: goldColor, backgroundColor: goldColor+'30', fill: true, tension: 0.3 }] },
    options: { responsive: true }
});

new Chart(document.getElementById('chartOerKer'), {
    type: 'line',
    data: { labels, datasets: [
        { label: 'OER (%)', data: @json($oerTrend), borderColor: greenColor, tension: 0.3 },
        { label: 'KER (%)', data: @json($kerTrend), borderColor: goldColor, tension: 0.3 }
    ]},
    options: { responsive: true }
});

new Chart(document.getElementById('chartComparison'), {
    type: 'bar',
    data: { labels: @json($comparisonLabels), datasets: [{ label: 'BTS Diproses (MT)', data: @json($comparisonBts), backgroundColor: greenColor }] },
    options: { responsive: true }
});

new Chart(document.getElementById('chartDowntime'), {
    type: 'bar',
    data: { labels: @json($comparisonLabels), datasets: [{ label: 'Downtime (jam)', data: @json($comparisonDowntime), backgroundColor: '#DC2626' }] },
    options: { responsive: true, indexAxis: 'y' }
});
</script>
@endsection
