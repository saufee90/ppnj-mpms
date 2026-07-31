<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<title>{{ $dataset['meta']['title'] }}</title>
<style>
    @page { margin: 12mm 11mm 14mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2937; line-height: 1.3; }
    .page { margin-bottom: 7px; }
    .pdf-section-intro,
    .pdf-content-block {
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .pdf-section-intro { margin-bottom: 2px; }
    .pdf-content-block { margin-bottom: 5px; }
    .pdf-chart-pair, .pdf-flow-block { page-break-before: auto; }
    .header { border-bottom: 2px solid #0b5d32; padding-bottom: 5px; margin-bottom: 7px; page-break-inside: avoid; page-break-after: avoid; }
    .header table, .grid, .metrics, .score, .flow, .summary { width: 100%; border-collapse: separate; border-spacing: 4px; }
    tr { page-break-inside: avoid; }
    .logo { width: 55px; }
    .brand { font-size: 8px; color: #4b5563; }
    .title { margin: 2px 0 0; color: #0b5d32; font-size: 15px; }
    .meta { text-align: right; font-size: 8px; color: #64748b; }
    h2 { color: #0b5d32; font-size: 14px; margin: 0 0 6px; border-left: 4px solid #c9a227; padding-left: 7px; page-break-after: avoid; }
    h3 { font-size: 10px; margin: 5px 0 3px; page-break-after: avoid; }
    .hero { background: #0b5d32; color: #fff; padding: 11px; margin-bottom: 7px; border-top: 4px solid #c9a227; page-break-inside: avoid; }
    .hero .scope { font-size: 19px; font-weight: bold; margin-top: 7px; }
    .hero .period { font-size: 12px; color: #d1fae5; }
    .card, .panel, .score-card { border: 1px solid #dbe5de; padding: 7px; vertical-align: top; page-break-inside: avoid; }
    .card { width: 16.66%; }
    .card-label { color: #64748b; font-size: 7px; text-transform: uppercase; }
    .card-value { font-size: 14px; font-weight: bold; margin: 3px 0; }
    .small { font-size: 7px; color: #64748b; }
    .status { display: inline-block; padding: 2px 5px; font-weight: bold; font-size: 7px; }
    .count { width: 25%; text-align: center; padding: 8px; border: 1px solid #dbe5de; }
    .count strong { display: block; font-size: 16px; }
    .chart { width: 100%; height: 170px; object-fit: contain; }
    .chart-cell { width: 50%; vertical-align: top; padding: 4px; page-break-inside: avoid; }
    .highlight { padding: 7px; margin-bottom: 5px; border-left: 4px solid #0b5d32; background: #f0fdf4; page-break-inside: avoid; }
    .attention { border-color: #d97706; background: #fffbeb; }
    .operation { border-color: #2563eb; background: #eff6ff; }
    .flow td { text-align: center; vertical-align: middle; }
    .flow .step { width: 20%; border-top: 4px solid #0b5d32; background: #f8fafc; padding: 10px 5px; }
    .flow .arrow { width: 6.66%; font-size: 18px; color: #0b5d32; }
    .score-card { width: 50%; border-left: 5px solid var(--status); }
    .score-name { font-weight: bold; font-size: 9px; }
    .score-values { width: 100%; margin-top: 6px; border-collapse: collapse; }
    .score-values td { width: 25%; padding-right: 4px; vertical-align: top; }
    .issue { border: 1px solid #fcd34d; background: #fffbeb; padding: 7px; margin: 5px 0; page-break-inside: avoid; }
    .no-data { padding: 12px; color: #64748b; background: #f8fafc; }
</style>
</head>
<body>
@php
    $overall = $dataset['overall'];
    $metrics = $overall['metrics'];
    $summary = $overall['operationalSummary'];
@endphp

<section class="page" data-pdf-section="executive-dashboard">
    <div class="hero"><table style="width:100%;border-collapse:collapse"><tr><td style="width:70px">@if($logoDataUri)<img src="{{ $logoDataUri }}" class="logo" alt="Logo PPNJ">@endif</td><td><div style="font-size:8px;font-weight:bold;color:#fde68a">MILL PERFORMANCE SYSTEM (MPS)</div><div style="font-size:10px">LAPORAN PRESTASI BULANAN PENGURUSAN</div><div class="scope">{{ $dataset['meta']['scope_label'] }}</div><div class="period">{{ strtoupper($dataset['meta']['period_label']) }}</div></td><td style="width:170px;text-align:right;vertical-align:top;font-size:8px">Dijana: {{ $presentation['generated_at'] }}@if($dataset['meta']['as_of_date'])<br>Data sehingga: {{ \Carbon\Carbon::parse($dataset['meta']['as_of_date'])->format('d/m/Y') }}@endif</td></tr></table></div>
    <h2>Executive Performance Dashboard</h2>
    @foreach($dataset['mills'] as $millReport)
        @if(count($dataset['mills']) > 1)<h3>{{ $millReport['mill']['name'] }}</h3>@endif
        <table class="metrics"><tr>@foreach($millReport['executiveCards'] as $card)@php $kpi=$card['kpi']; $status=$kpi['status']??'grey'; $statusMeta=$presentation['status_meta'][$status]??$presentation['status_meta']['grey']; $target=$card['code']==='bts_diproses'?($kpi['target']??null):($kpi['green_threshold']??null); $variance=$card['code']==='bts_diproses'?($kpi['variance_bts_diproses']??null):($kpi['variance']??null); @endphp<td class="card" style="border-top:4px solid {{ $kpi['colour']??'#9ca3af' }}"><div class="card-label">{{ $card['label'] }}</div><div class="card-value">{{ $card['actual']!==null?number_format((float)$card['actual'],2):'N/A' }} <span class="small">{{ $card['unit'] }}</span></div><span class="status" style="color:{{ $statusMeta['colour'] }};background:{{ $statusMeta['background'] }}">{{ $statusMeta['symbol'] }} {{ $status==='grey'&&($kpi['status_label']??'')==='Tidak Berkenaan'?'TIDAK BERKENAAN':$statusMeta['label'] }}</span><div class="small" style="margin-top:5px">Target: {{ $target!==null?number_format((float)$target,2):'KPI Belum Ditetapkan' }}<br>Variance: {{ $variance!==null?number_format((float)$variance,2):'-' }}</div></td>@endforeach</tr></table>
    @endforeach
    <h3>Ringkasan Prestasi</h3><table class="summary"><tr>@foreach(['green'=>'KPI Mencapai Sasaran','yellow'=>'KPI Perhatian','red'=>'KPI Tidak Mencapai Sasaran','grey'=>'KPI Belum Ditetapkan'] as $status=>$label)<td class="count" style="border-top:4px solid {{ $presentation['status_meta'][$status]['colour'] }}"><strong>{{ $presentation['status_counts'][$status] }}</strong>{{ $label }}</td>@endforeach</tr></table>
    <table class="grid"><tr><td class="panel" style="width:50%"><h3>Pencapaian Utama</h3>@forelse($presentation['highlights']['achievements'] as $text)<div class="highlight">✓ {{ $text }}</div>@empty<div class="no-data">Tiada pencapaian KPI berstatus hijau direkodkan.</div>@endforelse</td><td class="panel" style="width:50%"><h3>Perkara Memerlukan Perhatian</h3>@forelse($presentation['highlights']['attention'] as $text)<div class="highlight attention">! {{ $text }}</div>@empty<div class="no-data">Tiada KPI berstatus perhatian atau tidak capai direkodkan.</div>@endforelse</td></tr></table>
</section>

@if($dataset['flags']['hasProductionData'])
<section class="page" data-pdf-section="production">
    <div class="pdf-section-intro"><div class="header"><h2>Prestasi Penerimaan & Pengeluaran</h2><div class="small">{{ $dataset['meta']['scope_label'] }} · {{ $dataset['meta']['period_label'] }}</div></div><table class="metrics"><tr>@foreach(['bts_diterima'=>['BTS Diterima','MT'],'bts_diproses'=>['BTS Diproses','MT'],'pengeluaran_cpo'=>['Pengeluaran CPO','MT'],'pengeluaran_pk'=>['Pengeluaran PK','MT'],'jualan_cpo'=>['Jualan CPO','MT'],'jualan_pk'=>['Jualan PK','MT']] as $key=>[$label,$unit])<td class="card"><div class="card-label">{{ $label }}</div><div class="card-value">{{ number_format((float)$metrics[$key],2) }}</div><div class="small">{{ $unit }}</div></td>@endforeach</tr></table><div class="pdf-content-block pdf-chart-pair"><table class="grid"><tr><td class="chart-cell"><h3>Trend Harian BTS</h3>@if($charts['bts'])<img class="chart" src="{{ $charts['bts'] }}" alt="Trend BTS">@endif</td><td class="chart-cell"><h3>Trend Pengeluaran</h3>@if($charts['production'])<img class="chart" src="{{ $charts['production'] }}" alt="Trend pengeluaran">@endif</td></tr></table></div></div>
</section>
<section class="page" data-pdf-section="extraction">
    <div class="pdf-section-intro"><div class="header"><h2>Prestasi Extraction</h2><div class="small">{{ $dataset['meta']['scope_label'] }} · {{ $dataset['meta']['period_label'] }}</div></div><table class="metrics"><tr><td class="card"><div class="card-label">OER Bulanan</div><div class="card-value">{{ $metrics['oer']!==null?number_format((float)$metrics['oer'],2).'%':'N/A' }}</div></td><td class="card"><div class="card-label">KER Bulanan</div><div class="card-value">{{ $metrics['ker']!==null?number_format((float)$metrics['ker'],2).'%':'N/A' }}</div></td>@foreach(['highest_oer'=>'OER Tertinggi','lowest_oer'=>'OER Terendah','highest_ker'=>'KER Tertinggi','lowest_ker'=>'KER Terendah'] as $key=>$label)@php $extreme=$summary[$key]; @endphp<td class="card"><div class="card-label">{{ $label }}</div><div class="card-value">{{ $extreme?number_format((float)$extreme['value'],2).'%':'-' }}</div><div class="small">{{ $extreme?\Carbon\Carbon::parse($extreme['date'])->format('d/m/Y'):'Tiada data' }}</div></td>@endforeach</tr></table><div class="pdf-content-block pdf-chart-pair"><table class="grid"><tr><td class="chart-cell"><h3>Trend OER Harian</h3>@if($charts['oer'])<img class="chart" src="{{ $charts['oer'] }}" alt="Trend OER">@endif</td><td class="chart-cell"><h3>Trend KER Harian</h3>@if($charts['ker'])<img class="chart" src="{{ $charts['ker'] }}" alt="Trend KER">@endif</td></tr></table></div></div>
</section>
@endif

@if($presentation['has_records'])
<section class="page" data-pdf-section="operations">
    <div class="pdf-section-intro"><div class="header"><h2>Kecekapan Operasi & Downtime</h2><div class="small">{{ $dataset['meta']['scope_label'] }} · {{ $dataset['meta']['period_label'] }}</div></div><table class="metrics"><tr>@foreach(['record_days'=>['Hari Rekod','hari'],'operating_days'=>['Hari Operasi','hari'],'non_operating_days'=>['Hari Tidak Operasi','hari'],'process_hours'=>['Jam Proses','jam'],'downtime_hours'=>['Jam Downtime','jam'],'pooled_throughput'=>['Throughput','MT/Jam']] as $key=>[$label,$unit])<td class="card"><div class="card-label">{{ $label }}</div><div class="card-value">{{ $summary[$key]!==null?number_format((float)$summary[$key],2):'-' }}</div><div class="small">{{ $unit }}</div></td>@endforeach<td class="card"><div class="card-label">Downtime</div><div class="card-value">{{ $metrics['downtime_percentage']!==null?number_format((float)$metrics['downtime_percentage'],2).'%':'N/A' }}</div></td></tr></table><div class="pdf-content-block pdf-chart-pair"><table class="grid"><tr><td class="chart-cell" style="width:68%"><h3>Trend Downtime (%) Harian</h3>@if($charts['downtime'])<img class="chart" src="{{ $charts['downtime'] }}" alt="Trend downtime">@endif</td><td class="panel" style="width:32%"><h3>Downtime Tertinggi</h3>@if($summary['highest_downtime']&&(float)$summary['highest_downtime']['hours']>0)<div class="card-value">{{ number_format((float)$summary['highest_downtime']['hours'],2) }} jam</div><div>{{ \Carbon\Carbon::parse($summary['highest_downtime']['date'])->format('d/m/Y') }}</div>@else<div class="no-data">Tiada downtime direkodkan.</div>@endif</td></tr></table></div></div>
    @if($dataset['flags']['hasOperationalIssues'])<div class="pdf-content-block"><h3>Isu Operasi Direkodkan</h3>@foreach($dataset['mills'] as $millReport)@foreach($millReport['operationalIssues'] as $issue)<div class="issue"><strong>{{ $millReport['mill']['name'] }} · {{ \Carbon\Carbon::parse($issue['date'])->format('d/m/Y') }}</strong><br>{{ $issue['issue'] }}@if($issue['corrective_action'])<br><span class="small">Tindakan: {{ $issue['corrective_action'] }}</span>@endif</div>@endforeach @endforeach</div>@endif
</section>
<section class="page" data-pdf-section="product-flow">
    <div class="pdf-section-intro"><div class="header"><h2>Aliran Produk</h2><div class="small">{{ $dataset['meta']['scope_label'] }} · {{ $dataset['meta']['period_label'] }}</div></div><div class="pdf-content-block pdf-flow-block">@foreach(['cpo'=>'CPO','pk'=>'PK'] as $productKey=>$productLabel)@php $flow=$overall['productFlow'][$productKey]; @endphp<h3>{{ $productLabel }}</h3><table class="flow"><tr>@foreach(['opening_stock'=>'Stok Awal','production'=>'Pengeluaran','sales'=>'Jualan','closing_stock'=>'Stok Akhir'] as $key=>$label)<td class="step"><div class="card-label">{{ $label }}</div><div class="card-value">{{ $flow[$key]!==null?number_format((float)$flow[$key],2):'-' }}</div><div class="small">MT</div></td>@if(!$loop->last)<td class="arrow">→</td>@endif @endforeach</tr></table>@endforeach</div></div>
</section>
@endif

@foreach($presentation['mill_scorecards'] as $millScorecard)
<section class="page" data-pdf-section="kpi-scorecard"><div class="header"><h2>Prestasi KPI Bulanan</h2><div class="small">{{ $millScorecard['mill']['name'] }} · {{ $dataset['meta']['period_label'] }}</div></div><table class="score">@foreach(array_chunk($millScorecard['items'],2) as $row)<tr>@foreach($row as $item)<td class="score-card" style="--status:{{ $item['status_meta']['colour'] }}"><table style="width:100%"><tr><td><div class="score-name">{{ $item['name'] }}</div><div class="small">{{ $item['unit'] }}</div></td><td style="text-align:right"><span class="status" style="color:{{ $item['status_meta']['colour'] }};background:{{ $item['status_meta']['background'] }}">{{ $item['status_meta']['symbol'] }} {{ $item['status_meta']['label'] }}</span></td></tr></table><table class="score-values"><tr>@foreach($item['actuals'] as $actual)<td><div class="small">{{ $actual['label'] }}</div><strong>{{ $actual['value']!==null?number_format((float)$actual['value'],2):'-' }}</strong></td>@endforeach<td><div class="small">Target</div><strong>{{ $item['target']!==null?number_format((float)$item['target'],2):'KPI Belum Ditetapkan' }}</strong></td>@foreach($item['variances'] as $variance)<td><div class="small">{{ $variance['label'] }}</div><strong>{{ $variance['value']!==null?number_format((float)$variance['value'],2):'-' }}</strong></td>@endforeach</tr></table>@if($item['achievement']!==null)<div class="small" style="margin-top:5px">Pencapaian: <strong>{{ number_format((float)$item['achievement'],2) }}%</strong></div>@endif</td>@endforeach @if(count($row)===1)<td style="width:50%"></td>@endif</tr>@endforeach</table></section>
@endforeach

@if($dataset['flags']['showMillComparison'])
<section class="page" data-pdf-section="comparison"><div class="header"><h2>Perbandingan Kilang</h2><div class="small">Kahang vs Bukit Bujang · {{ $dataset['meta']['period_label'] }}</div></div><table class="grid"><tr><td class="chart-cell"><h3>Volum Pengeluaran (MT)</h3><img class="chart" src="{{ $charts['comparison']['volume'] }}" alt="Perbandingan volum"></td><td class="chart-cell"><h3>Extraction (%)</h3><img class="chart" src="{{ $charts['comparison']['extraction'] }}" alt="Perbandingan extraction"></td></tr><tr><td class="chart-cell"><h3>Throughput (MT/Jam)</h3><img class="chart" src="{{ $charts['comparison']['throughput'] }}" alt="Perbandingan throughput"></td><td class="chart-cell"><h3>Downtime (%)</h3><div class="small">Nilai lebih rendah menunjukkan prestasi downtime yang lebih baik.</div><img class="chart" src="{{ $charts['comparison']['downtime'] }}" alt="Perbandingan downtime"></td></tr></table></section>
@endif

@if($presentation['highlights']['achievements']||$presentation['highlights']['attention']||$presentation['highlights']['operations'])
<section class="page" data-pdf-section="management-summary"><div class="header"><h2>Rumusan Pengurusan</h2><div class="small">Fakta daripada dataset operasi dan KPI · {{ $dataset['meta']['period_label'] }}</div></div><table class="grid"><tr><td class="panel" style="width:33.33%"><h3>Pencapaian Utama</h3>@forelse($presentation['highlights']['achievements'] as $text)<div class="highlight">{{ $text }}</div>@empty<div class="no-data">Tiada pencapaian berstatus hijau.</div>@endforelse</td><td class="panel" style="width:33.33%"><h3>Perlu Perhatian</h3>@forelse($presentation['highlights']['attention'] as $text)<div class="highlight attention">{{ $text }}</div>@empty<div class="no-data">Tiada item perhatian.</div>@endforelse</td><td class="panel" style="width:33.33%"><h3>Pemerhatian Operasi</h3>@forelse($presentation['highlights']['operations'] as $text)<div class="highlight operation">{{ $text }}</div>@empty<div class="no-data">Tiada pemerhatian operasi direkodkan.</div>@endforelse</td></tr></table></section>
@endif

<script type="text/php">
if (isset($pdf)) {
    $font = $fontMetrics->get_font('DejaVu Sans', 'normal');
    $scope = @json($dataset['meta']['scope_label']);
    $period = @json($dataset['meta']['period_label']);
    $generated = @json($presentation['generated_at']);
    $pdf->page_text(34, 575, "MPS | {$scope} | {$period} | Dijana {$generated}", $font, 7, [0.35, 0.4, 0.38]);
    $pdf->page_text(735, 575, "Halaman {PAGE_NUM} / {PAGE_COUNT}", $font, 7, [0.35, 0.4, 0.38]);
}
</script>
</body>
</html>
