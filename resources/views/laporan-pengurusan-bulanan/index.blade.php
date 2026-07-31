@extends('layouts.app')
@section('title', 'Laporan Prestasi Bulanan Pengurusan')

@section('styles')
<style>
    .report-shell { width: 100%; max-width: 1440px; min-width: 0; margin: 0 auto; color: #17231c; }
    .report-hero { background: #0b5d32; color: white; border-top: 5px solid #c9a227; }
    .report-section { min-width: 0; background: white; border: 1px solid #dfe7e2; border-radius: 8px; }
    .section-kicker { color: #0b5d32; font-size: .72rem; font-weight: 800; text-transform: uppercase; }
    .metric-card { border: 1px solid #dfe7e2; border-radius: 7px; background: #fff; min-width: 0; }
    .chart-frame { position: relative; width: 100%; max-width: 100%; height: 310px; min-height: 310px; overflow: hidden; }
    .chart-frame canvas { width: 100% !important; max-width: 100% !important; height: 100% !important; }
    .chart-frame.compact { height: 260px; min-height: 260px; }
    .status-pill { display: inline-flex; align-items: center; gap: .35rem; border-radius: 999px; padding: .25rem .6rem; font-size: .68rem; font-weight: 800; white-space: nowrap; }
    .flow-step { border-top: 4px solid #0b5d32; min-width: 0; }
    .score-row { border-left: 5px solid var(--status-colour); background: #fff; }
    .score-progress { height: 6px; background: #e5e7eb; overflow: hidden; }
    .score-progress > span { display: block; height: 100%; max-width: 100%; background: var(--status-colour); }
    @media (max-width: 767px) { .chart-frame, .chart-frame.compact { height: 250px; min-height: 250px; } }
</style>
@endsection

@section('content')
@php
    $months = collect(range(1, 12))->mapWithKeys(fn ($value) => [$value => \Carbon\Carbon::create()->month($value)->translatedFormat('F')]);
    $overall = $dataset['overall'];
    $metrics = $overall['metrics'];
    $summary = $overall['operationalSummary'];
    $singleMill = $dataset['meta']['scope_type'] === 'single_mill' ? ($dataset['mills'][0] ?? null) : null;
@endphp

<div class="report-shell space-y-6">
    <form method="GET" action="{{ route('laporan-pengurusan-bulanan.index') }}" class="report-section p-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3 items-end">
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">Bulan</label><select name="bulan" class="w-full border border-gray-300 rounded-md px-3 py-2.5 text-sm bg-white">@foreach($months as $value => $label)<option value="{{ $value }}" {{ $month === $value ? 'selected' : '' }}>{{ $label }}</option>@endforeach</select></div>
        <div><label class="block text-xs font-semibold text-gray-600 mb-1">Tahun</label><select name="tahun" class="w-full border border-gray-300 rounded-md px-3 py-2.5 text-sm bg-white">@foreach($years as $optionYear)<option value="{{ $optionYear }}" {{ $year === $optionYear ? 'selected' : '' }}>{{ $optionYear }}</option>@endforeach</select></div>
        <div class="sm:col-span-2 xl:col-span-1"><label class="block text-xs font-semibold text-gray-600 mb-1">Skop Kilang</label>@if(auth()->user()->canViewAllMills())<select name="mill_id" class="w-full border border-gray-300 rounded-md px-3 py-2.5 text-sm bg-white"><option value="">Semua Kilang</option>@foreach($mills as $mill)<option value="{{ $mill->id }}" {{ (int) request('mill_id') === $mill->id ? 'selected' : '' }}>{{ $mill->name }}</option>@endforeach</select>@else<input type="text" value="{{ $mills->first()?->name }}" disabled class="w-full border border-gray-300 rounded-md px-3 py-2.5 text-sm bg-gray-50">@endif</div>
        <button class="h-10 px-4 rounded-md ppnj-green text-white text-sm font-semibold">Jana Laporan</button>
        <a href="{{ route('laporan-pengurusan-bulanan.pdf', array_filter(['bulan' => $month, 'tahun' => $year, 'mill_id' => $dataset['meta']['scope_mill_id']])) }}" class="h-10 px-4 rounded-md border border-green-800 text-green-800 text-sm font-semibold inline-flex items-center justify-center">Muat Turun PDF</a>
    </form>

    <section class="report-hero p-6 md:p-8">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
            <div><div class="flex items-center gap-4 mb-6"><img src="{{ asset('images/logo-ppnj.jpg') }}" alt="Logo PPNJ" class="w-20 h-auto bg-white p-1"><div><p class="text-xs font-bold text-green-100">MILL PERFORMANCE SYSTEM (MPS)</p><p class="text-sm text-green-100">Pertubuhan Peladang Negeri Johor</p></div></div><p class="text-xs font-bold text-yellow-300">LAPORAN PRESTASI BULANAN PENGURUSAN</p><h1 class="text-2xl md:text-3xl font-bold mt-2">{{ $dataset['meta']['scope_label'] }}</h1><p class="text-lg text-green-100 mt-1">{{ strtoupper($dataset['meta']['period_label']) }}</p></div>
            <div class="text-sm lg:text-right text-green-100"><p>Tarikh dijana</p><p class="font-bold text-white">{{ $presentation['generated_at'] }}</p>@if($dataset['meta']['as_of_date'])<p class="mt-3">Data sehingga</p><p class="font-bold text-white">{{ \Carbon\Carbon::parse($dataset['meta']['as_of_date'])->translatedFormat('d F Y') }}</p>@endif</div>
        </div>
    </section>

    <section class="report-section p-5 md:p-6" data-report-section="executive-dashboard">
        <div class="mb-5"><p class="section-kicker">01 / Executive Performance Dashboard</p><h2 class="text-xl font-bold mt-1">Prestasi Utama</h2></div>
        @foreach($dataset['mills'] as $millReport)
            @if(count($dataset['mills']) > 1)<h3 class="font-bold text-gray-800 mb-3 {{ !$loop->first ? 'mt-6' : '' }}">{{ $millReport['mill']['name'] }}</h3>@endif
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($millReport['executiveCards'] as $card)
                    @php
                        $cardKpi = $card['kpi']; $status = $cardKpi['status'] ?? 'grey'; $meta = $presentation['status_meta'][$status] ?? $presentation['status_meta']['grey'];
                        $target = $card['code'] === 'bts_diproses' ? ($cardKpi['target'] ?? null) : ($cardKpi['green_threshold'] ?? null);
                        $variance = $card['code'] === 'bts_diproses' ? ($cardKpi['variance_bts_diproses'] ?? null) : ($cardKpi['variance'] ?? null);
                    @endphp
                    <article class="metric-card p-4" style="border-top:4px solid {{ $cardKpi['colour'] ?? '#9CA3AF' }}"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-semibold text-gray-500">{{ $card['label'] }}</p><p class="text-2xl font-bold mt-1">{{ $card['actual'] !== null ? number_format((float) $card['actual'], 2) : 'Tidak Berkenaan' }} <span class="text-xs font-normal text-gray-500">{{ $card['actual'] !== null ? $card['unit'] : '' }}</span></p></div>@if($cardKpi)<span class="status-pill" style="color:{{ $meta['colour'] }};background:{{ $meta['background'] }}">{{ $meta['symbol'] }} {{ ($status === 'grey' && ($cardKpi['status_label'] ?? '') === 'Tidak Berkenaan') ? 'TIDAK BERKENAAN' : $meta['label'] }}</span>@endif</div><div class="grid grid-cols-2 gap-2 mt-4 text-xs"><div><p class="text-gray-400">Target</p><p class="font-bold">{{ $target !== null ? number_format((float) $target, 2) . ' ' . $card['unit'] : ($cardKpi ? 'KPI Belum Ditetapkan' : 'Tiada KPI rasmi') }}</p></div><div><p class="text-gray-400">Variance</p><p class="font-bold">{{ $variance !== null ? number_format((float) $variance, 2) . ' ' . $card['unit'] : '-' }}</p></div></div></article>
                @endforeach
            </div>
        @endforeach
        <div class="mt-7 border-t pt-5"><h3 class="font-bold mb-3">Ringkasan Prestasi</h3><div class="grid grid-cols-2 lg:grid-cols-4 gap-3">@foreach(['green' => 'KPI Mencapai Sasaran', 'yellow' => 'KPI Perhatian', 'red' => 'KPI Tidak Mencapai Sasaran', 'grey' => 'KPI Belum Ditetapkan'] as $status => $label)@php $meta = $presentation['status_meta'][$status]; @endphp<div class="p-3 border rounded-md" style="border-left:4px solid {{ $meta['colour'] }}"><p class="text-2xl font-bold">{{ $presentation['status_counts'][$status] }}</p><p class="text-xs text-gray-600">{{ $label }}</p></div>@endforeach</div></div>
        @if($presentation['highlights']['achievements'] || $presentation['highlights']['attention'])<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">@if($presentation['highlights']['achievements'])<div class="bg-green-50 border border-green-200 p-4 rounded-md"><h3 class="font-bold text-green-900 mb-2">Pencapaian Utama</h3>@foreach($presentation['highlights']['achievements'] as $text)<p class="text-sm text-green-900 mb-1">✓ {{ $text }}</p>@endforeach</div>@endif @if($presentation['highlights']['attention'])<div class="bg-amber-50 border border-amber-200 p-4 rounded-md"><h3 class="font-bold text-amber-900 mb-2">Perkara Memerlukan Perhatian</h3>@foreach($presentation['highlights']['attention'] as $text)<p class="text-sm text-amber-900 mb-1">! {{ $text }}</p>@endforeach</div>@endif</div>@endif
    </section>

    @if($dataset['flags']['hasProductionData'])
    <section class="report-section p-5 md:p-6" data-report-section="production"><p class="section-kicker">02 / Prestasi Penerimaan & Pengeluaran</p><h2 class="text-xl font-bold mt-1 mb-5">Aliran Bahan dan Produk</h2><div class="grid grid-cols-2 lg:grid-cols-6 gap-3 mb-6">@foreach(['bts_diterima' => ['BTS Diterima','MT'], 'bts_diproses' => ['BTS Diproses','MT'], 'pengeluaran_cpo' => ['Pengeluaran CPO','MT'], 'pengeluaran_pk' => ['Pengeluaran PK','MT'], 'jualan_cpo' => ['Jualan CPO','MT'], 'jualan_pk' => ['Jualan PK','MT']] as $key => [$label,$unit])<div class="metric-card p-3"><p class="text-xs text-gray-500">{{ $label }}</p><p class="text-lg font-bold mt-1">{{ number_format((float) $metrics[$key], 2) }}</p><p class="text-xs text-gray-400">{{ $unit }}</p></div>@endforeach</div><div class="grid grid-cols-1 xl:grid-cols-2 gap-5"><div><h3 class="font-bold mb-2">Trend Harian BTS</h3><div class="chart-frame"><canvas id="chart-bts"></canvas></div></div><div><h3 class="font-bold mb-2">Trend Pengeluaran</h3><div class="chart-frame"><canvas id="chart-production"></canvas></div></div></div></section>

    <section class="report-section p-5 md:p-6" data-report-section="extraction"><p class="section-kicker">03 / Prestasi Extraction</p><h2 class="text-xl font-bold mt-1 mb-5">OER dan KER</h2><div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3 mb-6">@foreach(['oer' => ['OER Bulanan','%'], 'ker' => ['KER Bulanan','%']] as $key => [$label,$unit])<div class="metric-card p-3"><p class="text-xs text-gray-500">{{ $label }}</p><p class="text-xl font-bold">{{ $metrics[$key] !== null ? number_format((float)$metrics[$key],2).' '.$unit : 'Tidak Berkenaan' }}</p></div>@endforeach @foreach(['highest_oer' => 'OER Tertinggi', 'lowest_oer' => 'OER Terendah', 'highest_ker' => 'KER Tertinggi', 'lowest_ker' => 'KER Terendah'] as $key => $label)@php $extreme = $summary[$key]; @endphp<div class="metric-card p-3"><p class="text-xs text-gray-500">{{ $label }}</p><p class="font-bold">{{ $extreme ? number_format((float)$extreme['value'],2).'%' : '-' }}</p><p class="text-xs text-gray-400">{{ $extreme ? \Carbon\Carbon::parse($extreme['date'])->format('d/m/Y') : 'Tiada data' }}</p></div>@endforeach</div><div class="grid grid-cols-1 xl:grid-cols-2 gap-5"><div><h3 class="font-bold mb-2">Trend OER Harian</h3><div class="chart-frame"><canvas id="chart-oer"></canvas></div></div><div><h3 class="font-bold mb-2">Trend KER Harian</h3><div class="chart-frame"><canvas id="chart-ker"></canvas></div></div></div></section>
    @endif

    @if($presentation['has_records'])
    <section class="report-section p-5 md:p-6" data-report-section="operations"><p class="section-kicker">04 / Kecekapan Operasi & Downtime</p><h2 class="text-xl font-bold mt-1 mb-5">Ketersediaan Operasi</h2><div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 mb-6">@foreach(['record_days' => ['Hari Mempunyai Rekod','hari'], 'operating_days' => ['Hari Operasi','hari'], 'non_operating_days' => ['Hari Tidak Operasi','hari'], 'process_hours' => ['Jumlah Jam Proses','jam'], 'downtime_hours' => ['Jumlah Jam Downtime','jam'], 'pooled_throughput' => ['Throughput','MT/Jam']] as $key => [$label,$unit])<div class="metric-card p-3"><p class="text-xs text-gray-500">{{ $label }}</p><p class="text-lg font-bold">{{ $summary[$key] !== null ? number_format((float)$summary[$key],2) : '-' }}</p><p class="text-xs text-gray-400">{{ $unit }}</p></div>@endforeach<div class="metric-card p-3"><p class="text-xs text-gray-500">Downtime</p><p class="text-lg font-bold">{{ $metrics['downtime_percentage'] !== null ? number_format((float)$metrics['downtime_percentage'],2).'%' : 'Tidak Berkenaan' }}</p></div></div><div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start"><div class="xl:col-span-2"><h3 class="font-bold mb-2">Trend Downtime (%) Harian</h3><div class="chart-frame"><canvas id="chart-downtime"></canvas></div></div><div class="space-y-3">@if($summary['highest_downtime'] && (float)$summary['highest_downtime']['hours'] > 0)<div class="bg-red-50 border border-red-200 p-4 rounded-md"><p class="text-xs font-bold text-red-700">DOWNTIME TERTINGGI</p><p class="text-xl font-bold text-red-900 mt-1">{{ number_format((float)$summary['highest_downtime']['hours'],2) }} jam</p><p class="text-sm text-red-800">{{ \Carbon\Carbon::parse($summary['highest_downtime']['date'])->translatedFormat('d F Y') }}</p></div>@endif @if($dataset['flags']['hasOperationalIssues'])<div class="border border-amber-300 bg-amber-50 p-4 rounded-md"><h3 class="font-bold text-amber-900">Isu Operasi Direkodkan</h3>@foreach($dataset['mills'] as $millReport)@foreach($millReport['operationalIssues'] as $issue)<div class="mt-3 text-sm"><p class="font-semibold">{{ $millReport['mill']['name'] }} · {{ \Carbon\Carbon::parse($issue['date'])->format('d/m/Y') }}</p><p>{{ $issue['issue'] }}</p>@if($issue['corrective_action'])<p class="text-gray-600 mt-1">Tindakan: {{ $issue['corrective_action'] }}</p>@endif</div>@endforeach @endforeach</div>@endif</div></div></section>

    <section class="report-section p-5 md:p-6" data-report-section="product-flow"><p class="section-kicker">05 / Aliran Produk</p><h2 class="text-xl font-bold mt-1 mb-5">Pergerakan CPO dan PK</h2>@foreach(['cpo' => 'CPO', 'pk' => 'PK'] as $productKey => $productLabel)@php $flow = $overall['productFlow'][$productKey]; @endphp<div class="mb-6 last:mb-0"><h3 class="font-bold mb-3">{{ $productLabel }}</h3><div class="grid grid-cols-1 sm:grid-cols-7 gap-2 items-stretch">@foreach(['opening_stock' => 'Stok Awal', 'production' => 'Pengeluaran', 'sales' => 'Jualan', 'closing_stock' => 'Stok Akhir'] as $key => $label)<div class="flow-step bg-gray-50 p-4 sm:col-span-1"><p class="text-xs text-gray-500">{{ $label }}</p><p class="text-xl font-bold mt-1">{{ $flow[$key] !== null ? number_format((float)$flow[$key],2) : '-' }}</p><p class="text-xs text-gray-400">MT</p></div>@if(!$loop->last)<div class="hidden sm:flex items-center justify-center text-2xl text-green-800">→</div>@endif @endforeach</div></div>@endforeach</section>
    @endif

    <section class="report-section p-5 md:p-6" data-report-section="kpi-scorecard"><p class="section-kicker">06 / Prestasi KPI Bulanan</p><h2 class="text-xl font-bold mt-1 mb-5">Scorecard KPI Rasmi</h2>@foreach($presentation['mill_scorecards'] as $millScorecard)<div class="mb-7 last:mb-0"><h3 class="font-bold mb-3">{{ $millScorecard['mill']['name'] }}</h3><div class="grid grid-cols-1 xl:grid-cols-2 gap-3">@foreach($millScorecard['items'] as $item)<article class="score-row border border-gray-200 p-4 rounded-md" style="--status-colour:{{ $item['status_meta']['colour'] }}"><div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2"><div><p class="font-bold">{{ $item['name'] }}</p><p class="text-xs text-gray-500">{{ $item['unit'] }}</p></div><span class="status-pill self-start" style="color:{{ $item['status_meta']['colour'] }};background:{{ $item['status_meta']['background'] }}">{{ $item['status_meta']['symbol'] }} {{ $item['status_meta']['label'] }}</span></div><div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 text-sm">@foreach($item['actuals'] as $actual)<div><p class="text-xs text-gray-400">{{ $actual['label'] }}</p><p class="font-bold">{{ $actual['value'] !== null ? number_format((float)$actual['value'],2) : '-' }}</p></div>@endforeach<div><p class="text-xs text-gray-400">Target</p><p class="font-bold">{{ $item['target'] !== null ? number_format((float)$item['target'],2) : 'KPI Belum Ditetapkan' }}</p></div>@foreach($item['variances'] as $variance)<div><p class="text-xs text-gray-400">{{ $variance['label'] }}</p><p class="font-bold">{{ $variance['value'] !== null ? number_format((float)$variance['value'],2) : '-' }}</p></div>@endforeach</div>@if($item['achievement'] !== null)<div class="mt-3"><div class="flex justify-between text-xs mb-1"><span>Pencapaian</span><strong>{{ number_format((float)$item['achievement'],1) }}%</strong></div><div class="score-progress"><span style="width:{{ min(100,(float)$item['achievement']) }}%"></span></div></div>@endif</article>@endforeach</div></div>@endforeach</section>

    @if($dataset['flags']['showMillComparison'])<section class="report-section p-5 md:p-6" data-report-section="comparison"><p class="section-kicker">07 / Perbandingan Kilang</p><h2 class="text-xl font-bold mt-1 mb-5">Kahang vs Bukit Bujang</h2><div class="grid grid-cols-1 xl:grid-cols-2 gap-5"><div><h3 class="font-bold mb-2">Volum Pengeluaran (MT)</h3><div class="chart-frame"><canvas id="chart-comparison-volume"></canvas></div></div><div><h3 class="font-bold mb-2">Extraction (%)</h3><div class="chart-frame"><canvas id="chart-comparison-extraction"></canvas></div></div><div><h3 class="font-bold mb-2">Throughput (MT/Jam)</h3><div class="chart-frame compact"><canvas id="chart-comparison-throughput"></canvas></div></div><div><h3 class="font-bold mb-2">Downtime (%)</h3><p class="text-xs text-gray-500 mb-2">Nilai lebih rendah menunjukkan prestasi downtime yang lebih baik.</p><div class="chart-frame compact"><canvas id="chart-comparison-downtime"></canvas></div></div></div></section>@endif

    @if($presentation['highlights']['achievements'] || $presentation['highlights']['attention'] || $presentation['highlights']['operations'])<section class="report-section p-5 md:p-6" data-report-section="management-summary"><p class="section-kicker">Rumusan Pengurusan</p><h2 class="text-xl font-bold mt-1 mb-5">Fakta Prestasi Direkodkan</h2><div class="grid grid-cols-1 lg:grid-cols-3 gap-4">@foreach(['achievements'=>['Pencapaian Utama','#DCFCE7','#14532D'], 'attention'=>['Perlu Perhatian','#FEF3C7','#78350F'], 'operations'=>['Pemerhatian Operasi','#DBEAFE','#1E3A8A']] as $key=>[$label,$background,$colour])@if($presentation['highlights'][$key])<div class="border p-4 rounded-md" style="background:{{ $background }};color:{{ $colour }}"><h3 class="font-bold mb-3">{{ $label }}</h3>@foreach($presentation['highlights'][$key] as $text)<p class="text-sm mb-2 last:mb-0">{{ $text }}</p>@endforeach</div>@endif @endforeach</div></section>@endif
</div>

<script type="application/json" id="management-monthly-report-dataset">@json($dataset)</script>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const dataset = JSON.parse(document.getElementById('management-monthly-report-dataset').textContent);
    const trend = dataset.overall.trend || [];
    const palette = ['#0B5D32', '#C9A227', '#2563EB', '#DC2626'];
    const targetLinePlugin = {
        id: 'managementTargetLine',
        afterDraw(chart, args, options) {
            if (options.value === null || options.value === undefined) return;
            const y = chart.scales.y.getPixelForValue(options.value);
            const ctx = chart.ctx;
            ctx.save();
            ctx.strokeStyle = '#7C3AED';
            ctx.setLineDash([7, 5]);
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(chart.chartArea.left, y);
            ctx.lineTo(chart.chartArea.right, y);
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.fillStyle = '#6D28D9';
            ctx.font = '11px sans-serif';
            ctx.fillText(`Sasaran ${Number(options.value).toFixed(2)}`, chart.chartArea.left + 6, Math.max(chart.chartArea.top + 12, y - 6));
            ctx.restore();
        }
    };
    Chart.register(targetLinePlugin);
    const commonOptions = unit => ({
        responsive: true,
        maintainAspectRatio: false,
        interaction: {mode: 'index', intersect: false},
        plugins: {
            legend: {position: 'bottom'},
            tooltip: {callbacks: {label: ctx => `${ctx.dataset.label}: ${Number(ctx.parsed.y).toLocaleString('ms-MY', {minimumFractionDigits: 2, maximumFractionDigits: 2})} ${unit}`}}
        },
        scales: {
            x: {title: {display: true, text: 'Tarikh'}},
            y: {beginAtZero: true, title: {display: true, text: unit}}
        }
    });
    const lineChart = (id, rows, definitions, unit, target = null) => {
        const canvas = document.getElementById(id);
        if (!canvas || !rows.length) return;
        const options = commonOptions(unit);
        options.plugins.managementTargetLine = {value: target};
        new Chart(canvas, {type: 'line', data: {labels: rows.map(row => row.date), datasets: definitions.map((item, index) => ({label: item.label, data: rows.map(row => row[item.key]), borderColor: palette[index], backgroundColor: palette[index], spanGaps: false, tension: .25, pointRadius: 3}))}, options});
    };
    const dailyBarChart = (id, rows, definition, unit, target = null) => {
        const canvas = document.getElementById(id);
        if (!canvas || !rows.length) return;
        const options = commonOptions(unit);
        options.plugins.managementTargetLine = {value: target};
        new Chart(canvas, {type: 'bar', data: {labels: rows.map(row => row.date), datasets: [{label: definition.label, data: rows.map(row => row[definition.key]), backgroundColor: palette[0], borderColor: palette[0], borderWidth: 1, barPercentage: .8, categoryPercentage: .9}]}, options});
    };
    const barChart = (id, labels, chartDatasets, unit) => {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        const options = commonOptions(unit);
        options.scales.x.title.text = 'Metrik';
        new Chart(canvas, {type: 'bar', data: {labels, datasets: chartDatasets}, options});
    };
    const single = dataset.mills.length === 1 ? dataset.mills[0] : null;
    lineChart('chart-bts', trend, [{key: 'bts_diterima', label: 'BTS Diterima'}, {key: 'bts_diproses', label: 'BTS Diproses'}], 'MT');
    lineChart('chart-production', trend, [{key: 'cpo', label: 'CPO'}, {key: 'pk', label: 'PK'}], 'MT');
    dailyBarChart('chart-oer', trend, {key: 'oer', label: 'OER'}, '%', single?.kpi?.oer?.green_threshold ?? null);
    dailyBarChart('chart-ker', trend, {key: 'ker', label: 'KER'}, '%', single?.kpi?.ker?.green_threshold ?? null);
    lineChart('chart-downtime', trend, [{key: 'downtime_percentage', label: 'Downtime'}], '%', single?.kpi?.downtime?.green_threshold ?? null);
    if (dataset.flags.showMillComparison) {
        const mills = dataset.comparison;
        const sets = keys => mills.map((mill, index) => ({label: mill.name, data: keys.map(key => mill.metrics[key]), backgroundColor: palette[index]}));
        barChart('chart-comparison-volume', ['BTS Diproses', 'CPO', 'PK'], sets(['bts_diproses', 'pengeluaran_cpo', 'pengeluaran_pk']), 'MT');
        barChart('chart-comparison-extraction', ['OER', 'KER'], sets(['oer', 'ker']), '%');
        barChart('chart-comparison-throughput', ['Throughput'], sets(['throughput']), 'MT/Jam');
        barChart('chart-comparison-downtime', ['Downtime'], sets(['downtime_percentage']), '%');
    }
});
</script>
@endsection
