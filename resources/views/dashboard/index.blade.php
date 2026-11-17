@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
@php
    $mpobProducts = $mpobPrices['products'] ?? [];
    $mpobSourceAvailable = (bool) ($mpobPrices['source_available'] ?? false);
    $mpobHasAnyPrice = collect($mpobProducts)->contains(fn ($product) => filled($product['price'] ?? null));
    $kpiCards = [
        ['key' => 'bts_diterima', 'name' => 'BTS Diterima', 'icon' => '📦', 'unit' => 'MT'],
        ['key' => 'bts_diproses', 'name' => 'BTS Diproses', 'icon' => '⚙️', 'unit' => 'MT'],
        ['key' => 'throughput', 'name' => 'Throughput', 'icon' => '↗', 'unit' => 'MT/Jam'],
        ['key' => 'pengeluaran_cpo', 'name' => 'Pengeluaran CPO', 'icon' => '🛢️', 'unit' => 'MT'],
        ['key' => 'jualan_cpo', 'name' => 'Jualan CPO', 'icon' => '↗', 'unit' => 'MT'],
        ['key' => 'oer', 'name' => 'OER', 'icon' => '◉', 'unit' => '%'],
        ['key' => 'pengeluaran_pk', 'name' => 'Pengeluaran PK', 'icon' => '🌰', 'unit' => 'MT'],
        ['key' => 'jualan_pk', 'name' => 'Jualan PK', 'icon' => '↗', 'unit' => 'MT'],
        ['key' => 'ker', 'name' => 'KER', 'icon' => '◉', 'unit' => '%'],
    ];
    $metricRows = [
        ['key' => 'bts_diterima', 'name' => 'BTS Diterima', 'unit' => 'MT'],
        ['key' => 'bts_diproses', 'name' => 'BTS Diproses', 'unit' => 'MT'],
        ['key' => 'throughput', 'name' => 'Throughput', 'unit' => 'MT/Jam'],
        ['key' => 'pengeluaran_cpo', 'name' => 'Pengeluaran CPO', 'unit' => 'MT'],
        ['key' => 'jualan_cpo', 'name' => 'Jualan CPO', 'unit' => 'MT'],
        ['key' => 'oer', 'name' => 'OER', 'unit' => '%'],
        ['key' => 'pengeluaran_pk', 'name' => 'Pengeluaran PK', 'unit' => 'MT'],
        ['key' => 'jualan_pk', 'name' => 'Jualan PK', 'unit' => 'MT'],
        ['key' => 'ker', 'name' => 'KER', 'unit' => '%'],
        ['key' => 'utilisation', 'name' => 'Utilisation', 'unit' => '%'],
    ];
    $periodLabels = ['harian' => 'Harian', 'mtd' => 'MTD', 'ytd' => 'YTD'];
@endphp

<style>
    .mps-dashboard { --mps-green: #0b5d32; --mps-dark: #073a27; --mps-gold: #c9a227; color: #17231d; }
    .mps-dashboard .section-heading { color: #173c2a; font-size: 1.05rem; font-weight: 750; letter-spacing: -.015em; }
    .mps-dashboard .section-caption { color: #748078; font-size: .78rem; }
    .mps-card { border: 1px solid #e8eee9; border-radius: 1rem; background: #fff; box-shadow: 0 5px 18px rgba(17, 52, 33, .045); }
    .mps-hero { position: relative; isolation: isolate; overflow: hidden; display: grid; grid-template-columns: minmax(0, 1fr) minmax(260px, 320px); align-items: center; gap: 1.25rem; margin-bottom: 1.35rem; padding: clamp(1.05rem, 1.5vw, 1.4rem); border: 1px solid #d3b66535; border-radius: 1.25rem; background: radial-gradient(ellipse at 78% 20%, #22614988, transparent 50%), linear-gradient(115deg, #042b20, #064330 62%, #123b35); color: #fff; box-shadow: 0 10px 28px #052d201a; }
    .mps-hero::before { content: ""; position: absolute; z-index: -1; width: 34rem; height: 24rem; right: 9%; bottom: -22rem; transform: rotate(-18deg); border: 1px solid #dfc06b55; border-radius: 50%; box-shadow: 0 0 0 1.4rem #dfc06b09, 0 0 0 3rem #dfc06b06; pointer-events: none; }
    .mps-hero::after { content: ""; position: absolute; inset: 0 auto 0 0; width: 3px; background: linear-gradient(#eedda3, #b58c28); }
    .mps-hero-eyebrow { color: #e8d494; font-size: .67rem; font-weight: 750; letter-spacing: .16em; }
    .mps-hero h1 { margin-top: .4rem; font-size: clamp(1.9rem, 2.6vw, 2.65rem); font-weight: 800; letter-spacing: -.045em; line-height: 1.1; }
    .mps-hero-subtitle { margin-top: .25rem; color: #dcebe1; font-size: .9rem; font-weight: 550; }
    .mps-hero-date { display: inline-flex; flex-wrap: wrap; align-items: center; gap: .35rem; margin-top: .6rem; padding: .3rem .6rem; border: 1px solid #ffffff27; border-radius: 999px; background: #ffffff0c; color: #f0f5f1; font-size: .7rem; }
    .mps-hero-controls { display: flex; flex-wrap: wrap; align-items: end; gap: .5rem; margin-top: .8rem; }
    .mps-hero-controls label { display: grid; gap: .28rem; color: #d2e3d8; font-size: .68rem; }
    .mps-hero-controls select, .mps-hero-controls input { min-height: 2.3rem; width: 100%; max-width: 12rem; border: 1px solid #ffffff35; border-radius: .55rem; background: #fff; padding: .4rem .65rem; color: #173c2a; font-size: .75rem; }
    .mps-hero-controls button, .mps-hero-controls a { display: inline-flex; min-height: 2.3rem; align-items: center; justify-content: center; border: 1px solid #e6cd79; border-radius: .55rem; background: #e6cd79; padding: .4rem .75rem; color: #173c2a; font-size: .76rem; font-weight: 700; text-decoration: none; }
    .weather-box { min-width: 0; border: 1px solid #ffffff47; border-radius: 1.1rem; background: radial-gradient(ellipse at 95% 10%, #e7b3dc30, transparent 70%), linear-gradient(125deg, #a4b4f130, #a0b6ed22 48%, #d1a4d632); padding: .85rem 1rem; backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); box-shadow: inset 0 1px 0 #ffffff17, 0 8px 24px #041c2933; }
    .weather-heading { display: flex; justify-content: space-between; align-items: center; gap: .5rem; }
    .weather-box h2 { color: #f7e9b9; font-size: .62rem; font-weight: 750; letter-spacing: .13em; text-transform: uppercase; }
    .weather-source { color: #e1e8f3; font-size: .58rem; }
    .weather-list { display: grid; gap: .6rem; margin-top: .55rem; }
    .weather-item { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; column-gap: .6rem; min-width: 0; border-top: 1px solid #ffffff25; padding-top: .6rem; }
    .weather-item:first-child { border-top: 0; padding-top: 0; }
    .weather-location { grid-column: 1 / -1; color: #f0f3fc; font-size: .68rem; font-weight: 600; overflow-wrap: anywhere; }
    .weather-reading { color: #fff; font-size: 2.35rem; font-weight: 750; letter-spacing: -.05em; line-height: 1.15; white-space: nowrap; }
    .weather-icon { grid-column: 2; grid-row: 2 / span 2; font-size: 2.5rem; line-height: 1.1; filter: drop-shadow(0 3px 8px #111a2830); }
    .weather-condition { color: #eef1fc; font-size: .73rem; }
    .weather-updated { grid-column: 1 / -1; margin-top: .3rem; color: #dfe7f3; font-size: .6rem; line-height: 1.45; }
    .weather-list.multiple { gap: .4rem; }
    .weather-list.multiple .weather-item { padding-top: .4rem; }
    .weather-list.multiple .weather-item:first-child { padding-top: 0; }
    .weather-list.multiple .weather-location { font-size: .62rem; }
    .weather-list.multiple .weather-reading { font-size: 1.4rem; }
    .weather-list.multiple .weather-icon { font-size: 1.9rem; }
    .weather-list.multiple .weather-condition { font-size: .62rem; }
    .weather-list.multiple .weather-updated { font-size: .56rem; margin-top: .2rem; }
    .weather-empty { grid-column: 1 / -1; color: #e1e8f3; font-size: .72rem; line-height: 1.45; }
    .mps-section { margin-bottom: 1.35rem; }
    .mps-section-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: .75rem; }
    .stock-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .85rem; }
    .stock-card { overflow: hidden; padding: 1rem; border-radius: 1rem; color: #fff; }
    .stock-card.kahang { background: linear-gradient(135deg, #f8b334, #dc8d16); color: #32240c; }
    .stock-card.bbj { background: linear-gradient(135deg, #2469b2, #16457f); }
    .stock-card h3 { font-size: .9rem; font-weight: 800; }
    .stock-date { margin-top: .15rem; opacity: .8; font-size: .68rem; }
    .stock-values { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .65rem; margin-top: .85rem; }
    .stock-value { min-width: 0; border: 1px solid #ffffff32; border-radius: .7rem; background: #ffffff18; padding: .7rem; }
    .stock-value .label { min-height: 2rem; font-size: .68rem; font-weight: 600; opacity: .88; }
    .stock-value .date { margin-top: .15rem; font-size: .62rem; opacity: .75; }
    .stock-value .number { margin-top: .45rem; font-size: clamp(1.15rem, 2vw, 1.7rem); font-weight: 800; letter-spacing: -.04em; overflow-wrap: anywhere; }
    .stock-value .unit { font-size: .68rem; font-weight: 650; letter-spacing: 0; }
    .mpob-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .85rem; }
    .mpob-card { min-width: 0; padding: 1rem; }
    .mpob-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; }
    .mpob-symbol { color: #173c2a; font-size: 1rem; font-weight: 800; }
    .mpob-card a { color: #0b5d32; font-size: .67rem; font-weight: 700; text-decoration: underline; }
    .mpob-card .price { margin-top: .55rem; color: #0b5d32; font-size: clamp(1.4rem, 2.2vw, 1.9rem); font-weight: 800; letter-spacing: -.04em; }
    .mpob-card .price-date, .mpob-meta { color: #7a857e; font-size: .67rem; }
    .mpob-delta { min-height: 1.1rem; margin-top: .2rem; color: #59665e; font-size: .68rem; font-weight: 650; }
    .mpob-chart { height: 105px; margin-top: .35rem; }
    .mpob-meta { display: grid; gap: .15rem; margin-top: .45rem; }
    .mpob-empty { display: grid; height: 100%; place-items: center; color: #89938d; font-size: .7rem; }
    .period-control { display: inline-flex; gap: .2rem; padding: .22rem; border: 1px solid #dfe8e1; border-radius: .7rem; background: #f4f8f5; }
    .period-control button { border: 0; border-radius: .5rem; background: transparent; padding: .42rem .75rem; color: #68756c; font-size: .73rem; font-weight: 700; cursor: pointer; }
    .period-control button.active { background: #0b5d32; color: #fff; box-shadow: 0 2px 6px #0b5d3230; }
    .kpi-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .7rem; }
    .kpi-card { display: flex; min-width: 0; min-height: 135px; flex-direction: column; border-top: 3px solid #0b5d32; padding: .8rem; }
    .kpi-name { display: flex; align-items: flex-start; gap: .4rem; color: #68756c; font-size: .72rem; font-weight: 700; line-height: 1.3; }
    .kpi-icon { flex: 0 0 auto; color: #b18a21; font-size: .88rem; }
    .kpi-number { margin-top: .55rem; color: #0b5d32; font-size: clamp(1.2rem, 1.7vw, 1.65rem); font-weight: 800; letter-spacing: -.045em; line-height: 1.1; overflow-wrap: anywhere; }
    .kpi-unit { margin-top: .15rem; color: #89938d; font-size: .64rem; }
    .kpi-status { margin-top: auto; padding-top: .5rem; font-size: .61rem; font-weight: 700; line-height: 1.3; }
    .kpi-status.green { color: #15803d; } .kpi-status.yellow { color: #b77910; } .kpi-status.red { color: #c33131; } .kpi-status.grey, .kpi-status.neutral { color: #8a938d; }
    .kpi-progress-summary { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .12rem .35rem; margin-top: .48rem; font-size: .58rem; font-weight: 700; line-height: 1.3; overflow-wrap: anywhere; }
    .kpi-progress-summary.green { color: #15803d; } .kpi-progress-summary.yellow { color: #9a6700; } .kpi-progress-summary.red { color: #b42318; }
    .kpi-progress-track { height: .32rem; margin-top: .26rem; overflow: hidden; border-radius: 999px; background: #e8eee9; }
    .kpi-progress-fill { height: 100%; border-radius: inherit; }
    .kpi-progress-fill.green { background: #15803d; } .kpi-progress-fill.yellow { background: #d99a16; } .kpi-progress-fill.red { background: #c33131; }
    .kpi-target { margin-top: .3rem; color: #68756c; font-size: .57rem; font-weight: 650; line-height: 1.35; overflow-wrap: anywhere; }
    .kpi-no-target { margin-top: auto; padding-top: .5rem; color: #68756c; font-size: .61rem; font-weight: 700; }
    html[data-theme="dark"] .mps-dashboard .kpi-progress-track { background: var(--mps-surface-soft, #1d3043); }
    html[data-theme="dark"] .mps-dashboard .kpi-progress-summary.green { color: #86efac; }
    html[data-theme="dark"] .mps-dashboard .kpi-progress-summary.yellow { color: #fcd34d; }
    html[data-theme="dark"] .mps-dashboard .kpi-progress-summary.red { color: #fca5a5; }
    html[data-theme="dark"] .mps-dashboard :is(.kpi-target, .kpi-no-target) { color: var(--mps-muted, #afc0ce); }
    .panel-grid { display: grid; grid-template-columns: minmax(0, 1.22fr) minmax(0, 1fr); gap: .85rem; }
    .panel { min-width: 0; padding: 1rem; }
    .trend-chart { position: relative; height: 290px; }
    .trend-empty { display: grid; min-height: 220px; place-items: center; border: 1px dashed #dbe5dd; border-radius: .8rem; color: #849087; font-size: .8rem; }
    .comparison-table { width: 100%; border-collapse: collapse; font-size: .7rem; }
    .comparison-table th { background: #f2f7f3; color: #54645a; font-size: .65rem; font-weight: 750; text-align: right; }
    .comparison-table th:first-child, .comparison-table td:first-child { text-align: left; }
    .comparison-table th, .comparison-table td { border-bottom: 1px solid #edf1ed; padding: .55rem .38rem; }
    .comparison-table td { color: #34443a; text-align: right; }
    .comparison-table td:first-child { font-weight: 650; }
    .comparison-table .total-row td { background: #f8faf8; color: #173c2a; font-weight: 800; }
    .comparison-note { margin-top: .55rem; color: #7a857e; font-size: .67rem; line-height: 1.4; }
    @media (min-width: 1280px) { .kpi-grid { grid-template-columns: repeat(9, minmax(0, 1fr)); } }
    @media (max-width: 1100px) { .mps-hero { grid-template-columns: minmax(0, 1fr) minmax(225px, .65fr); gap: 1rem; } }
    @media (min-width: 768px) and (max-width: 1023px) { .mps-hero { grid-template-columns: 1fr; } .weather-list.multiple { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .8rem; } .weather-list.multiple .weather-item { border-top: 0; padding-top: 0; } }
    @media (max-width: 900px) { .mpob-grid { grid-template-columns: 1fr; } .mpob-card { display: grid; grid-template-columns: minmax(0, 1fr) minmax(150px, .8fr); gap: 0 .8rem; } .mpob-card-head, .mpob-card .price, .mpob-card .mpob-delta, .mpob-card .mpob-meta { grid-column: 1; } .mpob-chart { grid-column: 2; grid-row: 1 / span 4; height: 100%; min-height: 110px; } }
    @media (max-width: 767px) { .panel-grid { grid-template-columns: 1fr; } .stock-grid { grid-template-columns: 1fr; } .mps-hero { grid-template-columns: 1fr; } .weather-box { max-width: none; } }
    @media (max-width: 767px) { .mps-hero-controls { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); } .mps-hero-controls label { min-width: 0; } .mps-hero-controls :is(select, input) { min-width: 0; max-width: 100%; } }
    @media (max-width: 600px) { .mpob-card { display: block; } .mpob-chart { height: 105px; min-height: 0; } .stock-values { gap: .45rem; } .stock-value { padding: .55rem; } .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .kpi-card { min-height: 128px; } .comparison-table { font-size: .63rem; } .comparison-table th, .comparison-table td { padding: .45rem .25rem; } }
</style>

<div class="mps-dashboard">
    <section class="mps-hero" aria-labelledby="mps-hero-title">
        <div>
            <p class="mps-hero-eyebrow">PERTUBUHAN PELADANG NEGERI JOHOR</p>
            <h1 id="mps-hero-title">MPS <span style="color:#ecd17f">Dashboard</span></h1>
            <p class="mps-hero-subtitle">Mill Performance System</p>
            <p class="mps-hero-date"><span aria-hidden="true">◷</span> Data operasi terkini: <strong>{{ $latestOperationDateText }}</strong></p>
            <form method="GET" action="{{ route('dashboard') }}" class="mps-hero-controls">
                @if(auth()->user()->canSelectDashboardMill())
                    <label>Kilang
                        <select name="mill_id">
                            <option value="" {{ empty($selectedMillId) ? 'selected' : '' }}>{{ auth()->user()->isEksekutifPemasaranBts() ? 'Gabungan Kedua-dua Kilang' : 'Semua Kilang' }}</option>
                            @foreach($mills as $mill)
                                <option value="{{ $mill->id }}" {{ (string) $selectedMillId === (string) $mill->id ? 'selected' : '' }}>{{ $mill->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <input type="hidden" name="period" value="{{ $selectedPeriod }}">
                <label>Tarikh operasi
                    <input type="date" name="tarikh" value="{{ $displayDateCarbon->toDateString() }}" max="{{ \Carbon\Carbon::yesterday()->toDateString() }}">
                </label>
                <button type="submit">Papar</button>
                @if(auth()->user()->canViewAllMills())
                    <a href="{{ route('dashboard.pdf', array_filter(['tarikh' => $displayDateCarbon->toDateString(), 'mill' => $mills->firstWhere('id', (int) $selectedMillId)?->code])) }}" target="_blank" rel="noopener noreferrer">Jana PDF</a>
                @endif
            </form>
        </div>
        <aside class="weather-box" aria-label="Cuaca semasa kilang" data-weather-url="{{ route('dashboard.weather', array_filter(['mill_id' => $selectedMillId])) }}">
            <div class="weather-heading"><h2>Cuaca Semasa</h2><span class="weather-source">Open-Meteo</span></div>
            <div class="weather-list" id="dashboard-weather" aria-live="polite">
                <p class="weather-empty" role="status">Memuatkan maklumat cuaca…</p>
            </div>
        </aside>
    </section>

    <section class="mps-section" aria-labelledby="stock-heading">
        <div class="mps-section-head">
            <div>
                <h2 id="stock-heading" class="section-heading">Status Stok Kilang</h2>
                <p class="section-caption">Baki dan stok menggunakan rekod sah terakhir yang tersedia.</p>
            </div>
        </div>
        @if($operationalStatusByMill->isEmpty())
            <div class="mps-card p-5 text-sm text-gray-500">Tiada rekod stok kilang yang tersedia.</div>
        @else
            <div class="stock-grid">
                @foreach($operationalStatusByMill as $millStatus)
                    @php $isKahang = $millStatus['code'] === 'KHG'; @endphp
                    <article class="stock-card {{ $isKahang ? 'kahang' : 'bbj' }}">
                        <h3>{{ $millStatus['name'] }}</h3>
                        <div class="stock-values">
                            <div class="stock-value">
                                <p class="label">Baki BTS Selepas Diproses</p>
                                <p class="date">Pada: {{ $millStatus['source_tarikh_text'] }}</p>
                                <p class="number">{{ $millStatus['baki_bts_selepas_diproses'] !== null ? number_format((float) $millStatus['baki_bts_selepas_diproses'], 2) : '—' }} <span class="unit">MT</span></p>
                            </div>
                            <div class="stock-value">
                                <p class="label">Stok CPO</p>
                                <p class="date">Pada: {{ $millStatus['stok_cpo_tarikh_text'] }}</p>
                                <p class="number">{{ $millStatus['stok_cpo'] !== null ? number_format((float) $millStatus['stok_cpo'], 2) : '—' }} <span class="unit">MT</span></p>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mps-section" aria-labelledby="mpob-heading">
        <div class="mps-section-head">
            <div>
                <h2 id="mpob-heading" class="section-heading">Harga Semasa MPOB</h2>
                <p class="section-caption">Harga Harian MPOB · carta menunjukkan rekod harga sebenar yang disimpan.</p>
                @if(! empty($mpobPrices['mpob_last_update']))
                    <p class="section-caption">Kemas kini sumber MPOB: {{ $mpobPrices['mpob_last_update'] }}</p>
                @endif
            </div>
            <p class="section-caption">Semakan sistem: {{ !empty($mpobPrices['checked_at']) ? \Carbon\Carbon::parse($mpobPrices['checked_at'])->translatedFormat('d F Y, H:i') : '—' }}</p>
        </div>
        @if(! $mpobSourceAvailable && $mpobHasAnyPrice)
            <div class="mps-card mb-3 border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">Sumber MPOB tidak dapat dicapai. Memaparkan data terakhir yang disimpan.</div>
        @endif
        <div class="mpob-grid">
            @foreach(['cpo' => 'CPO', 'pk' => 'PK', 'cpko' => 'CPKO'] as $code => $label)
                @php
                    $product = $mpobProducts[$code] ?? [];
                    $price = $product['price'] ?? null;
                    $priceDate = $product['price_date'] ?? null;
                    $trend = $mpobTrendData[$code] ?? ['labels' => [], 'values' => [], 'delta' => ['symbol' => '—', 'amount' => 0]];
                    $deltaSymbol = $trend['delta']['symbol'] ?? '—';
                    $deltaAmount = (float) ($trend['delta']['amount'] ?? 0);
                    $trendCount = count($trend['values'] ?? []);
                @endphp
                <article class="mps-card mpob-card">
                    <div class="mpob-card-head">
                        <div>
                            <p class="mpob-symbol">{{ $label }}</p>
                            <p class="price-date">{{ $priceDate ? \Carbon\Carbon::parse($priceDate)->translatedFormat('d F Y') : 'Tarikh belum tersedia' }}</p>
                        </div>
                        @if(! empty($mpobPrices['source_url']))
                            <a href="{{ $mpobPrices['source_url'] }}" target="_blank" rel="noopener noreferrer">Sumber MPOB</a>
                        @endif
                    </div>
                    <p class="price">{{ $price !== null ? 'RM ' . number_format((float) $price, 2) : '—' }}<span style="font-size:.7rem;font-weight:650;letter-spacing:0"> {{ $price !== null ? '/ MT' : 'Data belum tersedia' }}</span></p>
                    <p class="mpob-delta">
                        @if($deltaSymbol === '▲')
                            ▲ Naik RM {{ number_format(abs($deltaAmount), 2) }}
                        @elseif($deltaSymbol === '▼')
                            ▼ Turun RM {{ number_format(abs($deltaAmount), 2) }}
                        @else
                            — Tiada perubahan berbanding rekod sebelumnya
                        @endif
                    </p>
                    <div class="mpob-chart">
                        @if($trendCount > 0)
                            <canvas id="mpobSparkline-{{ $code }}" aria-label="Trend sebenar harga {{ $label }} MPOB"></canvas>
                        @else
                            <div class="mpob-empty">Trend belum tersedia.</div>
                        @endif
                    </div>
                    <div class="mpob-meta">
                        <span>Tarikh harga: {{ $priceDate ? \Carbon\Carbon::parse($priceDate)->translatedFormat('d F Y') : '—' }}</span>
                        <span>Kemas kini terakhir: {{ !empty($mpobPrices['refreshed_at']) ? \Carbon\Carbon::parse($mpobPrices['refreshed_at'])->translatedFormat('d F Y, H:i') : '—' }}</span>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="mps-section" aria-labelledby="kpi-heading">
        <div class="mps-section-head">
            <div>
                <h2 id="kpi-heading" class="section-heading">Prestasi Operasi Kilang</h2>
                <p class="section-caption">Nilai bagi {{ $periodLabels[$selectedPeriod] }} · tarikh operasi {{ $displayDateCarbon->translatedFormat('d F Y') }}</p>
            </div>
            <form method="GET" action="{{ route('dashboard') }}" class="period-control" aria-label="Pilih tempoh prestasi">
                @if($selectedMillId)
                    <input type="hidden" name="mill_id" value="{{ $selectedMillId }}">
                @endif
                <input type="hidden" name="tarikh" value="{{ $displayDateCarbon->toDateString() }}">
                @foreach($periodLabels as $period => $label)
                    <button type="submit" name="period" value="{{ $period }}" class="{{ $selectedPeriod === $period ? 'active' : '' }}" aria-pressed="{{ $selectedPeriod === $period ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </form>
        </div>
        @if(($showYtdBaselineNote ?? false) && $displayDateCarbon->month <= 6 && ! $displayDateCarbon->isLastOfMonth())
            <p class="section-caption" style="margin:-.25rem 0 .65rem">{{ $ytdNote ?? '' }}</p>
        @endif
        <div class="kpi-grid">
            @foreach($kpiCards as $card)
                @php
                    $kpiValue = $periodMetrics[$card['key']] ?? null;
                    $progress = $kpiProgress[$card['key']] ?? null;
                @endphp
                <article class="mps-card kpi-card">
                    <h3 class="kpi-name"><span class="kpi-icon" aria-hidden="true">{{ $card['icon'] }}</span>{{ $card['name'] }}</h3>
                    <p class="kpi-number">{{ $kpiValue !== null ? number_format((float) $kpiValue, 2) : '—' }}</p>
                    <p class="kpi-unit">{{ $card['unit'] }}</p>
                    @if($selectedPeriod !== 'harian' && $progress)
                        @if($progress['target'] !== null)
                            @if($progress['achievement_percentage'] !== null)
                                <div class="kpi-progress-summary {{ $progress['tone'] }}">
                                    <span>{{ number_format($progress['achievement_percentage'], 2) }}% dicapai</span>
                                    <span>{{ $progress['excess_percentage'] > 0 ? 'Lebih ' . number_format($progress['excess_percentage'], 2) . '%' : 'Baki ' . number_format($progress['remaining_percentage'], 2) . '%' }}</span>
                                </div>
                                <div class="kpi-progress-track" role="progressbar"
                                     aria-label="Pencapaian {{ $card['name'] }}"
                                     aria-valuemin="0" aria-valuemax="100"
                                     aria-valuenow="{{ $progress['bar_percentage'] }}"
                                     aria-valuetext="{{ number_format($progress['achievement_percentage'], 2) }}% dicapai">
                                    <div class="kpi-progress-fill {{ $progress['tone'] }}" style="width: {{ $progress['bar_percentage'] }}%"></div>
                                </div>
                                <p class="kpi-progress-summary {{ $progress['tone'] }}" style="justify-content:flex-start">{{ $progress['status_label'] }}</p>
                            @endif
                            <p class="kpi-target">{{ $progress['target_label'] }}: {{ number_format($progress['target'], 2) }} {{ $card['unit'] }}</p>
                        @else
                            <p class="kpi-no-target">Tiada Sasaran</p>
                        @endif
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    <section class="mps-section panel-grid" aria-label="Trend bulanan dan prestasi kilang">
        <article class="mps-card panel">
            <div class="mps-section-head">
                <div>
                    <h2 class="section-heading">Trend Prestasi Bulanan</h2>
                    <p class="section-caption">BTS Diproses (MT) · OER &amp; KER (%) · {{ $displayDateCarbon->translatedFormat('F Y') }}</p>
                </div>
            </div>
            @if($trendHasData)
                <div class="trend-chart"><canvas id="chartMonthlyTrend" aria-label="Carta BTS diproses, OER dan KER bulanan"></canvas></div>
            @else
                <div class="trend-empty">Tiada rekod operasi untuk tempoh bulanan ini.</div>
            @endif
        </article>
        <article class="mps-card panel">
            <div class="mps-section-head">
                <div>
                    <h2 class="section-heading">Prestasi Kilang</h2>
                    <p class="section-caption">{{ $periodLabels[$selectedPeriod] }} · {{ $displayDateCarbon->translatedFormat('d F Y') }}</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th>KPI</th>
                            <th>Kahang</th>
                            <th>KBB</th>
                            <th>Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($metricRows as $metric)
                            @php
                                $kahang = $comparisonMetrics['by_mill']['KHG'];
                                $bbj = $comparisonMetrics['by_mill']['BBJ'];
                                $totalValue = $comparisonMetrics['total'][$metric['key']] ?? null;
                                $kahangValue = $kahang[$metric['key']] ?? null;
                                $bbjValue = $bbj[$metric['key']] ?? null;
                            @endphp
                            <tr>
                                <td>{{ $metric['name'] }}</td>
                                <td>{{ $selectedMillId && $mills->firstWhere('id', (int) $selectedMillId)?->code !== 'KHG' ? '—' : ($kahangValue !== null ? number_format((float) $kahangValue, 2) : '—') }}</td>
                                <td>{{ $selectedMillId && $mills->firstWhere('id', (int) $selectedMillId)?->code !== 'BBJ' ? '—' : ($bbjValue !== null ? number_format((float) $bbjValue, 2) : '—') }}</td>
                                <td>{{ $totalValue !== null ? number_format((float) $totalValue, 2) : '—' }} <span style="color:#849087">{{ $metric['unit'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="comparison-note">
                @if($selectedMillId)
                    Paparan terhad kepada {{ $mills->firstWhere('id', (int) $selectedMillId)?->name ?? 'kilang dipilih' }}; kolum kilang lain disembunyikan. Jumlah menyamai kilang yang dipilih.
                @else
                    Jumlah BTS ialah jumlah kedua-dua kilang. OER, KER, Throughput dan Utilisation dikira semula daripada jumlah asas, bukan purata peratusan.
                @endif
            </p>
        </article>
    </section>

    @if($millsBelumHantar->count() > 0 || $qualityPendingCount > 0 || !empty($alertMessages))
        <section class="mps-section" aria-label="Makluman operasi">
            @if($millsBelumHantar->count() > 0)
                <div class="mps-card mb-2 border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">Data harian belum dihantar untuk: <strong>{{ $millsBelumHantar->implode(', ') }}</strong></div>
            @endif
            @if($qualityPendingCount > 0)
                <div class="mps-card mb-2 border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
                    {{ $qualityPendingCount }} rekod menunggu data kualiti.
                    @if(auth()->user()->canEditData())
                        <a href="{{ route('data-harian.quality-pending') }}" class="font-semibold underline">Isi sekarang</a>
                    @endif
                </div>
            @endif
            @foreach($alertMessages as $message)
                <div class="mps-card mb-2 border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{!! $message !!}</div>
            @endforeach
        </section>
    @endif
</div>
@endsection

@section('scripts')
<script>
(() => {
    const weatherBox = document.querySelector('.weather-box');
    const weatherList = document.getElementById('dashboard-weather');
    const labels = { KHG: 'Kilang Sawit PPNJ Kahang', BBJ: 'Kilang Sawit Bukit Bujang' };
    const weatherDateFormat = new Intl.DateTimeFormat('ms-MY', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
        hourCycle: 'h23', timeZone: 'Asia/Kuala_Lumpur',
    });
    if (weatherBox && weatherList) {
        fetch(weatherBox.dataset.weatherUrl, { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) throw new Error('Weather response unavailable');
                return response.json();
            })
            .then((payload) => {
                const weather = payload.weather || {};
                const entries = Object.entries(weather);
                weatherList.replaceChildren();
                weatherList.classList.toggle('multiple', entries.length > 1);
                if (!entries.length) {
                    weatherList.innerHTML = '<p class="weather-empty">Maklumat cuaca tidak tersedia.</p>';
                    return;
                }

                entries.forEach(([code, reading]) => {
                    const row = document.createElement('div');
                    row.className = 'weather-item';
                    const name = document.createElement('p');
                    name.className = 'weather-location';
                    name.textContent = labels[code] || code;
                    row.append(name);

                    if (reading.available) {
                        const condition = document.createElement('p');
                        condition.className = 'weather-condition';
                        condition.textContent = reading.condition;
                        const icon = document.createElement('span');
                        icon.className = 'weather-icon';
                        icon.setAttribute('aria-hidden', 'true');
                        icon.textContent = reading.icon;
                        const updated = document.createElement('p');
                        updated.className = 'weather-updated';
                        const rawTime = reading.updated_at;
                        const timestamp = typeof rawTime === 'string'
                            ? new Date(/[zZ]$|[+-]\d{2}:\d{2}$/.test(rawTime) ? rawTime : `${rawTime}+08:00`)
                            : null;
                        const updatedText = timestamp && !Number.isNaN(timestamp.getTime())
                            ? weatherDateFormat.format(timestamp)
                            : 'Masa belum tersedia';
                        updated.textContent = `Dikemas kini: ${updatedText}`;
                        const temperature = document.createElement('p');
                        temperature.className = 'weather-reading';
                        temperature.textContent = `${reading.temperature}°C`;
                        row.append(temperature, icon, condition, updated);
                    } else {
                        const unavailable = document.createElement('p');
                        unavailable.className = 'weather-empty';
                        unavailable.textContent = reading.message || 'Cuaca semasa tidak tersedia.';
                        row.append(unavailable);
                    }
                    weatherList.append(row);
                });
            })
            .catch(() => {
                weatherList.innerHTML = '<p class="weather-empty">Maklumat cuaca tidak dapat dimuatkan sekarang.</p>';
            });
    }

    if (!window.Chart) return;
    const mpobTrendData = @json($mpobTrendData ?? []);
    const compactDate = new Intl.DateTimeFormat('ms-MY', { day: 'numeric', month: 'short' });
    const fullDate = new Intl.DateTimeFormat('ms-MY', { day: 'numeric', month: 'long', year: 'numeric' });
    const numberFormat = new Intl.NumberFormat('ms-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    ['cpo', 'pk', 'cpko'].forEach((category) => {
        const trend = mpobTrendData[category] || { labels: [], values: [] };
        const canvas = document.getElementById(`mpobSparkline-${category}`);
        if (!canvas || !trend.labels?.length || !trend.values?.length) return;
        const singlePoint = trend.values.length === 1;
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: trend.labels,
                datasets: [{
                    data: trend.values,
                    borderColor: '#0b5d32',
                    backgroundColor: '#0b5d3214',
                    fill: true,
                    tension: .28,
                    pointRadius: singlePoint ? 3 : 1,
                    pointHoverRadius: 4,
                    borderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: (items) => {
                                const date = new Date(`${items[0]?.label || ''}T00:00:00`);
                                return Number.isNaN(date.getTime()) ? '' : fullDate.format(date);
                            },
                            label: (item) => `Harga: RM ${numberFormat.format(Number(item.raw))} / MT`,
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            autoSkip: true,
                            maxTicksLimit: 5,
                            maxRotation: 0,
                            callback: (_value, index) => {
                                const date = new Date(`${trend.labels[index]}T00:00:00`);
                                return Number.isNaN(date.getTime()) ? '' : compactDate.format(date);
                            },
                        },
                    },
                    y: {
                        beginAtZero: false,
                        ticks: { maxTicksLimit: 4, callback: (value) => Number(value).toLocaleString('ms-MY', { maximumFractionDigits: 0 }) },
                        title: { display: true, text: 'RM/MT', font: { size: 9 } },
                    },
                },
            },
        });
    });

    const trendCanvas = document.getElementById('chartMonthlyTrend');
    if (trendCanvas) {
        new Chart(trendCanvas, {
            type: 'bar',
            data: {
                labels: @json($labels),
                datasets: [
                    {
                        type: 'bar',
                        label: 'BTS Diproses (MT)',
                        data: @json($btsProcessedTrend),
                        yAxisID: 'yBts',
                        backgroundColor: '#0b5d32c9',
                        borderRadius: 4,
                        maxBarThickness: 22,
                    },
                    {
                        type: 'line',
                        label: 'OER (%)',
                        data: @json($oerTrend),
                        yAxisID: 'yRate',
                        borderColor: '#d89b20',
                        backgroundColor: '#d89b20',
                        tension: .28,
                        pointRadius: 2,
                        spanGaps: false,
                    },
                    {
                        type: 'line',
                        label: 'KER (%)',
                        data: @json($kerTrend),
                        yAxisID: 'yRate',
                        borderColor: '#3175bd',
                        backgroundColor: '#3175bd',
                        tension: .28,
                        pointRadius: 2,
                        spanGaps: false,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, font: { size: 10 } } } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 12, maxRotation: 0 } },
                    yBts: { type: 'linear', position: 'left', beginAtZero: true, title: { display: true, text: 'BTS (MT)' } },
                    yRate: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'Kadar (%)' } },
                },
            },
        });
    }
})();
</script>
@endsection
