@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')

@if(auth()->user()->canViewAllMills())
<div class="mb-6 flex justify-end">
    <a href="{{ route('dashboard.pdf') }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white" style="background-color: #0B5D32;">
        Jana PDF
    </a>
</div>
@endif

@if(auth()->user()->canViewAllMills())
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
@endif

<!-- Status Operasi Kilang -->
<div class="mb-6 rounded-xl shadow-md p-5 text-white" style="background-color: #0B5D32;">
    <h2 class="text-lg md:text-xl font-bold mb-4 tracking-wide">STATUS OPERASI KILANG</h2>
    <div class="grid grid-cols-1 {{ empty($selectedMillId) ? 'md:grid-cols-2' : '' }} gap-4">
        @foreach($operationalStatusByMill as $millStatus)
        <div class="rounded-lg border p-4" style="background-color: {{ $millStatus['code'] === 'KHG' ? '#F59E0B' : '#1E3A8A' }}; border-color: {{ $millStatus['code'] === 'KHG' ? 'rgba(31,41,55,0.35)' : 'rgba(255,255,255,0.35)' }};">
            <p class="text-sm md:text-base font-bold mb-3" style="color: {{ $millStatus['code'] === 'KHG' ? '#1F2937' : '#FFFFFF' }};">{{ $millStatus['name'] }}</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="rounded-md p-3" style="background-color: {{ $millStatus['code'] === 'KHG' ? 'rgba(255,255,255,0.32)' : 'rgba(255,255,255,0.12)' }};">
                    <div style="min-height: 52px;">
                        <p class="text-sm md:text-base font-semibold" style="color: {{ $millStatus['code'] === 'KHG' ? '#111827' : 'rgba(255,255,255,0.92)' }};">Baki BTS Selepas Diproses</p>
                        <p class="text-xs md:text-sm" style="color: {{ $millStatus['code'] === 'KHG' ? '#1F2937' : 'rgba(255,255,255,0.84)' }};">pada: {{ $millStatus['source_tarikh_text'] }}</p>
                    </div>
                    <p class="text-3xl md:text-4xl font-extrabold leading-tight mt-2" style="color: {{ $millStatus['code'] === 'KHG' ? '#111827' : '#FFFFFF' }};">{{ number_format($millStatus['baki_bts_selepas_diproses'] ?? 0, 2) }} <span class="text-lg md:text-xl font-bold">MT</span></p>
                </div>
                <div class="rounded-md p-3" style="background-color: {{ $millStatus['code'] === 'KHG' ? 'rgba(255,255,255,0.32)' : 'rgba(255,255,255,0.12)' }};">
                    <div style="min-height: 52px;">
                        <p class="text-sm md:text-base font-semibold" style="color: {{ $millStatus['code'] === 'KHG' ? '#111827' : 'rgba(255,255,255,0.92)' }};">Stok CPO</p>
                        <p class="text-xs md:text-sm" style="color: {{ $millStatus['code'] === 'KHG' ? '#1F2937' : 'rgba(255,255,255,0.84)' }};">pada: {{ $millStatus['source_tarikh_text'] }}</p>
                    </div>
                    <p class="text-3xl md:text-4xl font-extrabold leading-tight mt-2" style="color: {{ $millStatus['code'] === 'KHG' ? '#111827' : '#FFFFFF' }};">{{ number_format($millStatus['stok_cpo'] ?? 0, 2) }} <span class="text-lg md:text-xl font-bold">MT</span></p>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

<!-- Harga Harian MPOB -->
@php
    $mpobProducts = $mpobPrices['products'] ?? [];
    $mpobTrendData = $mpobTrendData ?? [];
    $mpobSourceAvailable = (bool) ($mpobPrices['source_available'] ?? false);
    $mpobHasAnyPrice = collect($mpobProducts)->contains(fn ($product) => filled($product['price'] ?? null));
@endphp
<div class="mb-6 rounded-xl shadow-md p-5 border border-gray-200 bg-white">
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between mb-4">
        <div>
            <h2 class="text-lg md:text-xl font-bold tracking-wide" style="color: #0B5D32;">HARGA HARIAN MPOB</h2>
            <p class="text-sm text-gray-500 mt-1">Harga semasa minyak sawit mentah daripada MPOB.</p>
            @if(!empty($mpobPrices['mpob_last_update']))
                <p class="text-xs text-gray-400 mt-1">MPOB last update: {{ $mpobPrices['mpob_last_update'] }}</p>
            @endif
        </div>
        <div class="text-sm text-gray-500 md:text-right space-y-1">
            <div>Semakan sistem: {{ $mpobPrices['checked_at'] ? \Carbon\Carbon::parse($mpobPrices['checked_at'])->translatedFormat('d F Y, H:i') : '-' }}</div>
            <div>Kemas kini terakhir: {{ $mpobPrices['refreshed_at'] ? \Carbon\Carbon::parse($mpobPrices['refreshed_at'])->translatedFormat('d F Y, H:i') : '-' }}</div>
        </div>
    </div>

    @if(! $mpobSourceAvailable && $mpobHasAnyPrice)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Sumber MPOB tidak dapat dicapai. Memaparkan data terakhir yang disimpan.
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        @foreach(['cpo' => 'CPO', 'pk' => 'PK', 'cpko' => 'CPKO'] as $code => $label)
            @php
                $product = $mpobProducts[$code] ?? [];
                $price = $product['price'] ?? null;
                $priceDate = $product['price_date'] ?? null;
                $trend = $mpobTrendData[$code] ?? ['labels' => [], 'values' => [], 'delta' => ['symbol' => '—', 'amount' => 0]];
                $deltaSymbol = $trend['delta']['symbol'] ?? '—';
                $deltaAmount = (float) ($trend['delta']['amount'] ?? 0);
                $trendCount = is_array($trend['values'] ?? null) ? count($trend['values']) : 0;
            @endphp
            <div class="rounded-lg border border-gray-200 p-4 bg-gradient-to-br from-white to-gray-50">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-gray-500">{{ $label }}</p>
                        <p class="text-xs text-gray-400 mt-1">{{ $priceDate ? \Carbon\Carbon::parse($priceDate)->translatedFormat('d F Y') : 'Tarikh belum tersedia' }}</p>
                    </div>
                    @if(! empty($mpobPrices['source_url']))
                        <a href="{{ $mpobPrices['source_url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold" style="color: #0B5D32;">Rujukan MPOB</a>
                    @endif
                </div>

                <div class="mt-4">
                    @if($price !== null)
                        <div class="text-2xl md:text-3xl font-extrabold" style="color: #0B5D32;">RM {{ number_format((float) $price, 2) }} <span class="text-sm font-bold text-gray-500">/ MT</span></div>
                    @else
                        <div class="text-sm font-semibold text-gray-500">Data harga belum tersedia</div>
                    @endif
                </div>

                <div class="mt-2 text-xs font-semibold text-gray-700">
                    @if($deltaSymbol === '▲')
                        <span>▲ Naik RM {{ number_format(abs($deltaAmount), 2) }} berbanding rekod sebelumnya</span>
                    @elseif($deltaSymbol === '▼')
                        <span>▼ Turun RM {{ number_format(abs($deltaAmount), 2) }} berbanding rekod sebelumnya</span>
                    @else
                        <span>— Tiada perubahan berbanding rekod sebelumnya</span>
                    @endif
                </div>

                <div class="mt-2">
                    <div class="h-[112px] md:h-[128px]">
                        <canvas id="mpobSparkline-{{ $code }}" aria-label="Trend {{ $label }} 30 hari"></canvas>
                    </div>
                    @if($trendCount === 0)
                        <p class="mt-1 text-xs text-gray-400">Trend belum tersedia.</p>
                    @elseif($trendCount === 1)
                        <p class="mt-1 text-xs text-gray-400">Trend mengandungi 1 rekod sahaja.</p>
                    @endif
                </div>

                <div class="mt-3 text-xs text-gray-500 space-y-1">
                    <div>Tarikh harga: {{ $priceDate ? \Carbon\Carbon::parse($priceDate)->translatedFormat('d F Y') : '-' }}</div>
                    <div>Semakan sistem: {{ $mpobPrices['checked_at'] ? \Carbon\Carbon::parse($mpobPrices['checked_at'])->translatedFormat('d F Y, H:i') : '-' }}</div>
                </div>
            </div>
        @endforeach
    </div>
</div>

<!-- Info kemas kini data & hari operasi (ikut pilihan kilang) -->
@if($isAllMillsSelected)
    <div class="mb-4 text-sm text-gray-600">Data Terkini: Data Operasi sehingga {{ $latestOperationDateText }}</div>
@else
    <div class="mb-4 text-sm text-gray-600">🟢 Data operasi sehingga: {{ $latestOperationDateText }} | Dikemas kini: {{ $lastUpdatedText }}</div>
    @if(isset($operatedDays))
        <div class="mb-4 text-sm font-semibold text-gray-700">Hari Operasi: {{ $operatedDays }}/{{ $referenceDays }}</div>
    @endif
@endif

<!-- Alert: data belum dihantar -->
@if($millsBelumHantar->count() > 0)
<div class="mb-6 p-4 rounded-xl bg-yellow-50 border border-yellow-200 text-yellow-800 text-sm">
    ⚠️ Data harian belum dihantar untuk: <strong>{{ $millsBelumHantar->implode(', ') }}</strong>
</div>
@endif

<!-- Alert: data kualiti tertunggak -->
@if($qualityPendingCount > 0)
<div class="mb-3 p-3 rounded-xl bg-blue-50 border border-blue-200 text-blue-700 text-sm">
    @if(auth()->user()->canEditData())
        🧪 {{ $qualityPendingCount }} rekod menunggu data kualiti (OER/KER/dll) — <a href="{{ route('data-harian.quality-pending') }}" class="underline font-medium">isi sekarang</a>
    @else
        🧪 {{ $qualityPendingCount }} rekod menunggu data kualiti (OER/KER/dll).
    @endif
</div>
@endif

<!-- Alert: OER/Downtime/FFA -->
@if(!empty($alertMessages))
    @foreach($alertMessages as $message)
        <div class="mb-3 p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm">
            {!! $message !!}
        </div>
    @endforeach
@endif

<!-- Metrik Daily / MTD / YTD - Metric Cards -->
<div class="mb-8">
    <h2 class="text-lg font-bold text-gray-800 mb-6">📊 Performa Harian, Bulanan & Tahunan</h2>
    @if($showYtdBaselineNote ?? false)
        <p class="text-xs text-gray-500 -mt-4 mb-5">YTD dikira bermula 01 Julai 2026</p>
    @endif
    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        
        <!-- BTS Diterima -->
        <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition-shadow border-t-4" style="border-color: #0B5D32;">
            <div class="flex items-center justify-between mb-4">
                <p class="text-gray-600 font-medium">📦 BTS Diterima (MT)</p>
            </div>
            <p class="text-4xl font-bold mb-4" style="color: #0B5D32;">{{ number_format($dailyMetrics['bts_diterima'], 2) }}</p>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-blue-50 rounded-lg p-3 border-l-2 border-blue-400">
                    <p class="text-xs text-gray-600 mb-1">Bulan Ini (MTD)</p>
                    <p class="text-sm font-semibold text-blue-700">{{ number_format($mtdMetrics['bts_diterima'], 2) }}</p>
                </div>
                <div class="bg-yellow-50 rounded-lg p-3 border-l-2 border-yellow-400">
                    <p class="text-xs text-gray-600 mb-1">Tahun Ini (YTD)</p>
                    <p class="text-sm font-semibold text-yellow-700">{{ number_format($ytdMetrics['bts_diterima'], 2) }}</p>
                </div>
            </div>
        </div>

        <!-- BTS Diproses -->
        <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition-shadow border-t-4" style="border-color: #0B5D32;">
            <div class="flex items-center justify-between mb-4">
                <p class="text-gray-600 font-medium">📊 BTS Diproses (MT)</p>
            </div>
            <p class="text-4xl font-bold mb-4" style="color: #0B5D32;">{{ number_format($dailyMetrics['bts_diproses'], 2) }}</p>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-blue-50 rounded-lg p-3 border-l-2 border-blue-400">
                    <p class="text-xs text-gray-600 mb-1">Bulan Ini (MTD)</p>
                    <p class="text-sm font-semibold text-blue-700">{{ number_format($mtdMetrics['bts_diproses'], 2) }}</p>
                </div>
                <div class="bg-yellow-50 rounded-lg p-3 border-l-2 border-yellow-400">
                    <p class="text-xs text-gray-600 mb-1">Tahun Ini (YTD)</p>
                    <p class="text-sm font-semibold text-yellow-700">{{ number_format($ytdMetrics['bts_diproses'], 2) }}</p>
                </div>
            </div>
        </div>

        <!-- Throughput -->
        <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition-shadow border-t-4" style="border-color: #0B5D32;">
            <div class="flex items-center justify-between mb-4">
                <p class="text-gray-600 font-medium">⚡ Throughput (MT/Jam)</p>
            </div>
            @php
                $dailyThroughput = ($dailyMetrics['jam_proses'] ?? 0) > 0 ? (($dailyMetrics['bts_diproses'] ?? 0) / $dailyMetrics['jam_proses']) : 0;
                $mtdThroughput = ($mtdMetrics['jam_proses'] ?? 0) > 0 ? (($mtdMetrics['bts_diproses'] ?? 0) / $mtdMetrics['jam_proses']) : 0;
                $ytdThroughput = ($ytdMetrics['jam_proses'] ?? 0) > 0 ? (($ytdMetrics['bts_diproses'] ?? 0) / $ytdMetrics['jam_proses']) : 0;
            @endphp
            <p class="text-4xl font-bold mb-4" style="color: #0B5D32;">{{ number_format($dailyThroughput, 2) }}</p>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-blue-50 rounded-lg p-3 border-l-2 border-blue-400">
                    <p class="text-xs text-gray-600 mb-1">Bulan Ini (MTD)</p>
                    <p class="text-sm font-semibold text-blue-700">{{ number_format($mtdThroughput, 2) }}</p>
                </div>
                <div class="bg-yellow-50 rounded-lg p-3 border-l-2 border-yellow-400">
                    <p class="text-xs text-gray-600 mb-1">Tahun Ini (YTD)</p>
                    <p class="text-sm font-semibold text-yellow-700">{{ number_format($ytdThroughput, 2) }}</p>
                </div>
            </div>
        </div>

        <!-- Pengeluaran CPO -->
        <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition-shadow border-t-4" style="border-color: #0B5D32;">
            <div class="flex items-center justify-between mb-4">
                <p class="text-gray-600 font-medium">🛢️ Pengeluaran CPO (MT)</p>
            </div>
            <p class="text-4xl font-bold mb-4" style="color: #0B5D32;">{{ number_format($dailyMetrics['produksi_cpo'] ?? 0, 2) }}</p>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-blue-50 rounded-lg p-3 border-l-2 border-blue-400">
                    <p class="text-xs text-gray-600 mb-1">Bulan Ini (MTD)</p>
                    <p class="text-sm font-semibold text-blue-700">{{ number_format($mtdMetrics['produksi_cpo'] ?? 0, 2) }}</p>
                </div>
                <div class="bg-yellow-50 rounded-lg p-3 border-l-2 border-yellow-400">
                    <p class="text-xs text-gray-600 mb-1">Tahun Ini (YTD)</p>
                    <p class="text-sm font-semibold text-yellow-700">{{ number_format($ytdMetrics['produksi_cpo'] ?? 0, 2) }}</p>
                </div>
            </div>
        </div>

        <!-- Jualan CPO -->
        <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition-shadow border-t-4" style="border-color: #0B5D32;">
            <div class="flex items-center justify-between mb-4">
                <p class="text-gray-600 font-medium">🛒 Jualan CPO (MT)</p>
            </div>
            <p class="text-4xl font-bold mb-4" style="color: #0B5D32;">{{ number_format($dailyMetrics['penjualan_cpo'], 2) }}</p>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-blue-50 rounded-lg p-3 border-l-2 border-blue-400">
                    <p class="text-xs text-gray-600 mb-1">Bulan Ini (MTD)</p>
                    <p class="text-sm font-semibold text-blue-700">{{ number_format($mtdMetrics['pengeluaran_cpo'], 2) }}</p>
                </div>
                <div class="bg-yellow-50 rounded-lg p-3 border-l-2 border-yellow-400">
                    <p class="text-xs text-gray-600 mb-1">Tahun Ini (YTD)</p>
                    <p class="text-sm font-semibold text-yellow-700">{{ number_format($ytdMetrics['pengeluaran_cpo'], 2) }}</p>
                </div>
            </div>
        </div>

        <!-- OER -->
        <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition-shadow border-t-4" style="border-color: #0B5D32;">
            <div class="flex items-center justify-between mb-4">
                <p class="text-gray-600 font-medium">⚙️ OER (%)</p>
            </div>
            <p class="text-4xl font-bold mb-4" style="color: #0B5D32;">{{ number_format($dailyMetrics['oer_rata'], 2) }}</p>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-blue-50 rounded-lg p-3 border-l-2 border-blue-400">
                    <p class="text-xs text-gray-600 mb-1">Bulan Ini (MTD)</p>
                    <p class="text-sm font-semibold text-blue-700">{{ number_format($mtdMetrics['oer_rata'], 2) }}</p>
                </div>
                <div class="bg-yellow-50 rounded-lg p-3 border-l-2 border-yellow-400">
                    <p class="text-xs text-gray-600 mb-1">Tahun Ini (YTD)</p>
                    <p class="text-sm font-semibold text-yellow-700">{{ number_format($ytdMetrics['oer_rata'], 2) }}</p>
                </div>
            </div>
        </div>

        <!-- Pengeluaran PK -->
        <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition-shadow border-t-4" style="border-color: #0B5D32;">
            <div class="flex items-center justify-between mb-4">
                <p class="text-gray-600 font-medium">🎯 Pengeluaran PK (MT)</p>
            </div>
            <p class="text-4xl font-bold mb-4" style="color: #0B5D32;">{{ number_format($dailyMetrics['produksi_pk'] ?? 0, 2) }}</p>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-blue-50 rounded-lg p-3 border-l-2 border-blue-400">
                    <p class="text-xs text-gray-600 mb-1">Bulan Ini (MTD)</p>
                    <p class="text-sm font-semibold text-blue-700">{{ number_format($mtdMetrics['produksi_pk'] ?? 0, 2) }}</p>
                </div>
                <div class="bg-yellow-50 rounded-lg p-3 border-l-2 border-yellow-400">
                    <p class="text-xs text-gray-600 mb-1">Tahun Ini (YTD)</p>
                    <p class="text-sm font-semibold text-yellow-700">{{ number_format($ytdMetrics['produksi_pk'] ?? 0, 2) }}</p>
                </div>
            </div>
        </div>

        <!-- Jualan PK -->
        <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition-shadow border-t-4" style="border-color: #0B5D32;">
            <div class="flex items-center justify-between mb-4">
                <p class="text-gray-600 font-medium">🛒 Jualan PK (MT)</p>
            </div>
            <p class="text-4xl font-bold mb-4" style="color: #0B5D32;">{{ number_format($dailyMetrics['penjualan_pk'], 2) }}</p>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-blue-50 rounded-lg p-3 border-l-2 border-blue-400">
                    <p class="text-xs text-gray-600 mb-1">Bulan Ini (MTD)</p>
                    <p class="text-sm font-semibold text-blue-700">{{ number_format($mtdMetrics['pengeluaran_pk'], 2) }}</p>
                </div>
                <div class="bg-yellow-50 rounded-lg p-3 border-l-2 border-yellow-400">
                    <p class="text-xs text-gray-600 mb-1">Tahun Ini (YTD)</p>
                    <p class="text-sm font-semibold text-yellow-700">{{ number_format($ytdMetrics['pengeluaran_pk'], 2) }}</p>
                </div>
            </div>
        </div>

        <!-- KER -->
        <div class="bg-white rounded-xl shadow-md p-6 hover:shadow-lg transition-shadow border-t-4" style="border-color: #0B5D32;">
            <div class="flex items-center justify-between mb-4">
                <p class="text-gray-600 font-medium">⚙️ KER (%)</p>
            </div>
            <p class="text-4xl font-bold mb-4" style="color: #0B5D32;">{{ number_format($dailyMetrics['ker_rata'] ?? 0, 2) }}</p>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-blue-50 rounded-lg p-3 border-l-2 border-blue-400">
                    <p class="text-xs text-gray-600 mb-1">Bulan Ini (MTD)</p>
                    <p class="text-sm font-semibold text-blue-700">{{ number_format($mtdMetrics['ker_rata'], 2) }}</p>
                </div>
                <div class="bg-yellow-50 rounded-lg p-3 border-l-2 border-yellow-400">
                    <p class="text-xs text-gray-600 mb-1">Tahun Ini (YTD)</p>
                    <p class="text-sm font-semibold text-yellow-700">{{ number_format($ytdMetrics['ker_rata'], 2) }}</p>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Carta -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-600 mb-3">Trend BTS Diproses (Bulan Semasa)</p>
        <canvas id="chartBts"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-600 mb-3">Trend OER (Bulan Semasa)</p>
        <canvas id="chartOer"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-600 mb-3">Trend KER (Bulan Semasa)</p>
        <canvas id="chartKer"></canvas>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-600 mb-3">Trend Downtime (Bulan Semasa)</p>
        <canvas id="chartDowntime"></canvas>
    </div>
</div>

@endsection

@section('scripts')
<script>
const labels = @json($labels);
const greenColor = '#0B5D32';
const goldColor = '#C9A227';
const mpobTrendData = @json($mpobTrendData ?? []);

new Chart(document.getElementById('chartBts'), {
    type: 'line',
    data: { labels, datasets: [{ label: 'BTS Diproses (MT)', data: @json($btsProcessedTrend), borderColor: greenColor, backgroundColor: greenColor+'20', fill: true, tension: 0.3 }] },
    options: { responsive: true }
});

new Chart(document.getElementById('chartOer'), {
    type: 'line',
    data: { labels, datasets: [{ label: 'OER (%)', data: @json($oerTrend), borderColor: greenColor, backgroundColor: greenColor+'20', fill: true, tension: 0.3 }] },
    options: { responsive: true }
});

new Chart(document.getElementById('chartKer'), {
    type: 'line',
    data: { labels, datasets: [{ label: 'KER (%)', data: @json($kerTrend), borderColor: goldColor, backgroundColor: goldColor+'30', fill: true, tension: 0.3 }] },
    options: { responsive: true }
});

new Chart(document.getElementById('chartDowntime'), {
    type: 'line',
    data: { labels, datasets: [{ label: 'Downtime (jam)', data: @json($downtimeTrend), borderColor: '#DC2626', backgroundColor: '#DC262620', fill: true, tension: 0.3 }] },
    options: { responsive: true }
});

if (window.Chart) {
    const compactLabelFormatter = new Intl.DateTimeFormat('ms-MY', { day: 'numeric', month: 'short' });
    const fullLabelFormatter = new Intl.DateTimeFormat('ms-MY', { day: 'numeric', month: 'long', year: 'numeric' });
    const numberFormatter = new Intl.NumberFormat('ms-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    ['cpo', 'pk', 'cpko'].forEach((category) => {
        const trend = mpobTrendData[category] || { labels: [], values: [] };
        const trendLabels = Array.isArray(trend.labels) ? trend.labels : [];
        const trendValues = Array.isArray(trend.values) ? trend.values : [];
        if (!trendLabels.length || !trendValues.length) {
            return;
        }

        const canvas = document.getElementById(`mpobSparkline-${category}`);
        if (!canvas) {
            return;
        }

        const hasSinglePoint = trendValues.length === 1;

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    data: trendValues,
                    borderColor: '#0B5D32',
                    backgroundColor: '#0B5D3214',
                    fill: false,
                    tension: 0.3,
                    pointRadius: hasSinglePoint ? 3 : 1,
                    pointHoverRadius: 5,
                    pointHitRadius: 12,
                    borderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'nearest',
                    intersect: false,
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: (items) => {
                                const rawDate = items?.[0]?.label;
                                if (!rawDate) {
                                    return '-';
                                }
                                const date = new Date(rawDate + 'T00:00:00');
                                if (Number.isNaN(date.getTime())) {
                                    return rawDate;
                                }
                                return fullLabelFormatter.format(date);
                            },
                            label: (item) => `Harga: RM ${numberFormatter.format(Number(item.raw || 0))} / MT`,
                        },
                    },
                },
                scales: {
                    x: {
                        display: true,
                        grid: {
                            display: false,
                        },
                        ticks: {
                            autoSkip: true,
                            maxTicksLimit: 6,
                            maxRotation: 0,
                            minRotation: 0,
                            color: '#6B7280',
                            callback: (_value, index) => {
                                const rawDate = trendLabels[index];
                                if (!rawDate) {
                                    return '';
                                }
                                const date = new Date(rawDate + 'T00:00:00');
                                if (Number.isNaN(date.getTime())) {
                                    return rawDate;
                                }
                                return compactLabelFormatter.format(date);
                            },
                        },
                    },
                    y: {
                        display: true,
                        beginAtZero: false,
                        grid: {
                            color: 'rgba(107, 114, 128, 0.18)',
                        },
                        ticks: {
                            color: '#6B7280',
                            maxTicksLimit: 4,
                            callback: (value) => Number(value).toLocaleString('ms-MY', { maximumFractionDigits: 0 }),
                        },
                        title: {
                            display: true,
                            text: 'RM/MT',
                            color: '#4B5563',
                            font: {
                                size: 10,
                                weight: '600',
                            },
                        },
                    },
                },
            },
        });
    });
}
</script>
@endsection
