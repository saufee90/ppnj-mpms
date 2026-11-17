@extends('layouts.app')
@section('title', 'Dashboard FFB')

@section('content')
@php
    $labels = \App\Support\FfbReceivePresentation::CATEGORY_LABELS;
    $colors = ['ppnj_owned' => '#0B5D32', 'ppnj_ppk_integrated' => '#b78916', 'external_supplier' => '#2563eb'];
    $rankingTitles = [
        'overall' => ['Top 5 Keseluruhan', 'Gabungan entiti daripada ketiga-tiga kategori'],
        'ppnj_ppk_integrated' => ['Top 5 PPK Rasmi', 'Ladang usahasama bebas tidak dimasukkan dalam ranking PPK rasmi'],
        'independent_integrated_estate' => ['Top 5 Ladang Usahasama Bersepadu', 'Dipaparkan berasingan dan bukan PPK rasmi'],
        'external_supplier' => ['Top 5 Pembekal Luar', 'Alias digabung mengikut pembekal kanonik'],
        'ppnj_owned' => ['Top 5 Ladang Milik PPNJ', 'Agregat mengikut identiti ladang kanonik'],
    ];
    $scope = $filters['mill'] === 'all' ? 'Gabungan KBB + Kahang' : ($filters['mill'] === 'BBJ' ? 'KBB' : 'Kahang');
@endphp
<style>
    .ffb-dashboard { --ffb-green: #0b5d32; color: #172f28; }
    .ffb-dashboard .ffb-hero { background: linear-gradient(120deg, #073b25, #0b5d32 65%, #197951); color: white; }
    .ffb-dashboard .ffb-eyebrow { letter-spacing: .14em; font-size: .7rem; font-weight: 700; text-transform: uppercase; }
    .ffb-dashboard .ffb-panel { background: #fff; border: 1px solid #e4eae6; border-radius: 1rem; box-shadow: 0 3px 14px #153d2410; }
    .ffb-dashboard .ffb-kpis { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
    .ffb-dashboard .ffb-input { display: block; width: 100%; padding: .65rem .75rem; border: 1px solid #cdd9d1; border-radius: .6rem; background: #fff; margin-top: .35rem; color: #172f28; }
    .ffb-dashboard .ffb-chart { position: relative; height: 260px; }
    .ffb-dashboard .ffb-table { width: 100%; text-align: left; font-size: .875rem; border-collapse: collapse; }
    .ffb-dashboard .ffb-table th { padding: .75rem; background: #f5f8f6; color: #53665b; font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; }
    .ffb-dashboard .ffb-table td { padding: .9rem .75rem; border-top: 1px solid #edf1ee; vertical-align: middle; }
    .ffb-dashboard .ffb-table .ffb-number { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .ffb-dashboard .ffb-rank { display: inline-flex; align-items: center; justify-content: center; width: 1.8rem; height: 1.8rem; border-radius: 50%; background: #eff5f0; color: #0b5d32; font-weight: 700; }
    .ffb-dashboard .ffb-rank-first { background: #fbefcb; color: #805c0b; }
    @media(min-width: 1280px) { .ffb-dashboard .ffb-kpis { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
    @media(max-width: 480px) { .ffb-dashboard .ffb-kpis { grid-template-columns: 1fr; } }
</style>

<div class="ffb-dashboard space-y-6">
    <header class="ffb-hero rounded-2xl p-6 md:p-8">
        <div class="flex flex-wrap items-start justify-between gap-5">
            <div>
                <p class="ffb-eyebrow text-green-200">Penerimaan buah tandan segar</p>
                <h2 class="mt-2 text-2xl font-bold md:text-3xl">Dashboard FFB</h2>
                <p class="mt-2 text-sm text-green-100">Prestasi penerimaan dan kedudukan penyumbang berdasarkan tiket yang telah disahkan.</p>
                <div class="mt-4 flex flex-wrap gap-2 text-xs">
                    <span class="rounded-full bg-white/15 px-3 py-1">{{ $scope }}</span>
                    <span class="rounded-full bg-white/15 px-3 py-1">{{ \Carbon\Carbon::parse($filters['start'])->format('d/m/Y') }} &ndash; {{ \Carbon\Carbon::parse($filters['end'])->format('d/m/Y') }}</span>
                    <span class="rounded-full bg-white/15 px-3 py-1">Disahkan sahaja &middot; Baca sahaja</span>
                </div>
                @if($filters['start'] <= \App\Services\FfbDailyReceiptService::PARALLEL_RUN_END && $filters['end'] >= \App\Services\FfbDailyReceiptService::PARALLEL_RUN_START)
                <p class="mt-3 rounded-lg border border-amber-300 bg-amber-100 px-3 py-2 text-sm font-semibold text-amber-950">
                    Nota Parallel Run (05/11/2026–30/11/2026): data dalam tempoh ini ialah latihan dan bukan KPI operasi rasmi.
                </p>
                @endif
            </div>
            <a href="{{ auth()->user()->isEksekutifPemasaranBts() ? route('ffb-receive.reports.index') : route('ffb-receive.index') }}" class="rounded-lg border border-white/40 px-4 py-2 text-sm font-semibold hover:bg-white/10">{{ auth()->user()->isEksekutifPemasaranBts() ? 'Laporan BTS' : 'FFB Receive' }} &rarr;</a>
        </div>
    </header>

    <form method="GET" action="{{ route('ffb-receive.dashboard') }}" class="ffb-panel p-5" id="ffb-dashboard-filters">
        <div class="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <label class="text-sm font-semibold">Kilang
                <select name="mill" class="ffb-input">
                    @if(auth()->user()->canViewFfbReceiveReports())
                    <option value="all" @selected($filters['mill'] === 'all')>Gabungan KBB + Kahang</option>
                    @endif
                    @foreach($mills as $mill)
                    <option value="{{ $mill->code }}" @selected($filters['mill'] === $mill->code)>{{ $mill->code === 'BBJ' ? 'KBB' : 'Kahang' }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold">Tempoh
                <select name="period" id="ffb-period" class="ffb-input">
                    @foreach(['daily' => 'Harian', 'monthly' => 'Bulanan', 'yearly' => 'Tahunan', 'range' => 'Julat Tarikh'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold" data-period="daily">Tarikh
                <input type="date" name="date" value="{{ $filters['date'] }}" min="1000-01-01" class="ffb-input">
            </label>
            <label class="text-sm font-semibold" data-period="monthly">Bulan
                <input type="month" name="month" value="{{ $filters['month'] }}" min="1000-01" class="ffb-input">
            </label>
            <label class="text-sm font-semibold" data-period="yearly">Tahun
                <input type="number" name="year" value="{{ $filters['year'] }}" min="1000" max="9999" class="ffb-input">
            </label>
            <label class="text-sm font-semibold" data-period="range">Tarikh Mula
                <input type="date" name="start" value="{{ $filters['start'] }}" min="1000-01-01" class="ffb-input">
            </label>
            <label class="text-sm font-semibold" data-period="range">Tarikh Akhir
                <input type="date" name="end" value="{{ $filters['end'] }}" min="1000-01-01" class="ffb-input">
            </label>
            <div class="flex items-center gap-3">
                <button class="rounded-lg ppnj-green px-5 py-3 text-sm font-semibold text-white hover:opacity-90">Papar Dashboard</button>
                <a href="{{ route('ffb-receive.dashboard', ['mill' => $filters['mill']]) }}" class="text-sm text-green-800 underline">Terkini</a>
            </div>
        </div>
        <p class="mt-4 text-xs text-gray-500">Tarikh pengesahan FFB terkini dalam skop ini: {{ $latest ? \Carbon\Carbon::parse($latest)->format('d/m/Y') : 'Belum ada data disahkan' }}. Skop pegawai dan pengurus terhad kepada kilang masing-masing.</p>
    </form>

    @if(!$data['has_data'])
    <div role="status" class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
        <strong>Tiada penerimaan disahkan dalam tempoh ini.</strong>
        <p class="mt-1">Batch dimuat naik, sedia untuk semakan, gagal atau dibatalkan tidak dikira. Cuba tempoh lain atau semak FFB Receive.</p>
    </div>
    @endif

    <section class="ffb-kpis" aria-label="Ringkasan penerimaan">
        <article class="ffb-panel p-5" style="border-top: 4px solid #0b5d32">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Jumlah BTS Diterima</p>
            <p class="mt-3 text-3xl font-bold text-green-900">{{ number_format($data['total_mt'], 2) }} <span class="text-sm font-medium">MT</span></p>
            <p class="mt-3 text-xs text-gray-500">Jumlah berat tiket disahkan</p>
        </article>
        <article class="ffb-panel p-5" style="border-top: 4px solid #475569">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Jumlah Tiket Timbang</p>
            <p class="mt-3 text-3xl font-bold">{{ number_format($data['ticket_count']) }}</p>
            <p class="mt-3 text-xs text-gray-500">Tidak termasuk pengakuan sifar</p>
        </article>
        @foreach($data['categories'] as $code => $category)
        <article class="ffb-panel p-5" style="border-top: 4px solid {{ $colors[$code] }}">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $labels[$code] }}</p>
            <p class="mt-3 text-2xl font-bold" style="color: {{ $colors[$code] }}">{{ number_format($category['mt'], 2) }} <span class="text-sm font-medium">MT</span></p>
            <div class="mt-3 flex justify-between text-xs text-gray-500"><span>{{ number_format($category['tickets']) }} tiket</span><strong>{{ number_format($category['percentage'], 2) }}%</strong></div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full" style="background: {{ $colors[$code] }}; width: {{ $category['percentage'] }}%"></div></div>
        </article>
        @endforeach
    </section>

    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
        <div><strong>Tiada Penerimaan BTS:</strong> {{ $data['zero_mill_days'] }} pengakuan sifar (hari-kilang), meliputi {{ $data['zero_dates'] }} tarikh.</div>
        <span class="text-xs text-gray-500">0.00 MT &middot; 0 tiket &middot; {{ $data['confirmed_days'] }} hari-kilang disahkan keseluruhan</span>
    </div>

    <section class="grid gap-5 xl:grid-cols-3" aria-label="Visualisasi penerimaan">
        <article class="ffb-panel p-5 xl:col-span-2">
            <h3 class="font-bold">Trend Penerimaan Harian</h3>
            <p class="mb-4 mt-1 text-xs text-gray-500">Mengikut tarikh penerimaan, bukan tarikh pengesahan. Hanya tarikh dengan rekod disahkan dipaparkan.</p>
            <div class="ffb-chart"><canvas id="ffb-trend" role="img" aria-label="Trend jumlah BTS diterima harian"></canvas></div>
        </article>
        <article class="ffb-panel p-5">
            <h3 class="font-bold">Komposisi Penerimaan</h3>
            <p class="mb-4 mt-1 text-xs text-gray-500">Bahagian berat daripada jumlah tempoh dipilih</p>
            @if($data['total_mt'] > 0)
            <div class="ffb-chart"><canvas id="ffb-composition" role="img" aria-label="Komposisi tiga kategori BTS"></canvas></div>
            @else
            <div class="flex h-64 items-center justify-center rounded-xl bg-gray-50 text-sm text-gray-500">Tiada berat untuk komposisi.</div>
            @endif
        </article>
    </section>

    <section class="ffb-panel p-5" aria-label="Perbandingan kilang">
        <div class="mb-4 flex flex-wrap justify-between gap-2">
            <h3 class="font-bold">Perbandingan Kilang</h3>
            <p class="text-xs text-gray-500">Mengikut skop akses dan penapis kilang</p>
        </div>
        <div class="grid items-center gap-5 lg:grid-cols-2">
            <div class="ffb-chart" style="height: 190px"><canvas id="ffb-mills" role="img" aria-label="Perbandingan berat BTS KBB dan Kahang mengikut akses"></canvas></div>
            <div class="overflow-x-auto">
                <table class="ffb-table">
                    <thead><tr><th>Kilang</th><th class="ffb-number">BTS (MT)</th><th class="ffb-number">Tiket</th><th class="ffb-number">Hari sifar</th></tr></thead>
                    <tbody>
                        @foreach($data['mills'] as $mill)
                        <tr><td class="font-semibold">{{ $mill['name'] }}</td><td class="ffb-number">{{ number_format($mill['mt'], 2) }}</td><td class="ffb-number">{{ number_format($mill['tickets']) }}</td><td class="ffb-number">{{ $mill['zero_days'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="grid gap-5 xl:grid-cols-2" aria-label="Kedudukan penyumbang">
        @foreach($rankingTitles as $key => [$title, $description])
        <article class="ffb-panel overflow-hidden">
            <div class="border-b border-gray-100 p-5">
                <h3 class="font-bold">{{ $title }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ $description }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="ffb-table">
                    <thead><tr><th><span class="sr-only">Kedudukan</span>#</th><th>Entiti / Kategori</th><th class="ffb-number">Jumlah MT</th><th class="ffb-number">Sumbangan</th></tr></thead>
                    <tbody>
                        @forelse($data['rankings'][$key] as $entity)
                        <tr>
                            <td><span class="ffb-rank {{ $loop->first ? 'ffb-rank-first' : '' }}">{{ $loop->iteration }}</span></td>
                            <td class="min-w-[180px]">
                                <span class="block font-semibold">{{ $entity['name'] }}</span>
                                <span class="mt-1 inline-block text-xs" style="color: {{ $colors[$entity['category']] }}">{{ $labels[$entity['category']] }}</span>
                                <span class="block text-xs text-gray-500">{{ $entity['tickets'] }} tiket</span>
                            </td>
                            <td class="ffb-number font-bold">{{ number_format($entity['mt'], 2) }}</td>
                            <td class="ffb-number">{{ number_format($entity['percentage'], 2) }}%</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="py-10 text-center text-gray-500">Tiada penyumbang disahkan dalam tempoh ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
        @endforeach
    </section>

    <details class="ffb-panel p-5 text-sm">
        <summary class="cursor-pointer font-semibold">Nota data &amp; butiran trend</summary>
        <ul class="mt-3 list-disc space-y-2 pl-5 text-xs text-gray-600">
            <li>Hanya batch berstatus confirmed dalam slot aktif dikira. Pengakuan sifar bukan tiket timbang.</li>
            <li>Peratus sumbangan semua jadual ialah bahagian daripada jumlah BTS dalam skop dipilih, bukan jumlah Top 5.</li>
            <li>Sumber bersepadu digabung mengikut identiti induk; Top 5 PPK rasmi mengecualikan entiti ladang bebas yang dipaparkan berasingan. Ladang dan pembekal lain mengikut sumber kanonik. Nama serupa tidak digabung secara automatik.</li>
            <li>Nama bersumberkan snapshot tiket terawal dalam skop dipilih. Perubahan nama master tidak mengubah identiti atau snapshot sejarah.</li>
            <li>Agregat menggunakan ketepatan penuh tiket; paparan dibundarkan kepada 2 perpuluhan. Jumlah mungkin berbeza sedikit daripada penjumlahan jumlah harian yang telah dibundarkan.</li>
            <li>Tarikh tanpa rekod disahkan tidak dianggap sebagai pengakuan sifar. Skop gabungan boleh mempunyai pengesahan daripada satu kilang sahaja.</li>
            <li>Jika carta tidak dimuatkan, semua nilai tersedia dalam kad, jadual dan butiran trend ini.</li>
        </ul>
        <div class="mt-4 max-h-80 overflow-auto">
            <table class="ffb-table">
                <thead><tr><th>Tarikh</th><th class="ffb-number">BTS (MT)</th><th class="ffb-number">Tiket</th><th class="ffb-number">Pengakuan sifar</th></tr></thead>
                <tbody>
                    @forelse($data['trend'] as $day)
                    <tr><td>{{ $day['date'] }}</td><td class="ffb-number">{{ number_format($day['mt'], 2) }}</td><td class="ffb-number">{{ $day['tickets'] }}</td><td class="ffb-number">{{ $day['zero_days'] }}</td></tr>
                    @empty
                    <tr><td colspan="4">Tiada data disahkan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </details>
</div>
<script>
(() => {
    const form = document.getElementById('ffb-dashboard-filters');
    const period = document.getElementById('ffb-period');
    function syncPeriod() {
        form.querySelectorAll('[data-period]').forEach(label => {
            const active = label.dataset.period === period.value;
            label.hidden = !active;
            label.querySelectorAll('input').forEach(input => { input.disabled = !active; input.required = active; });
        });
    }
    period.addEventListener('change', syncPeriod);
    syncPeriod();
    if (typeof Chart === 'undefined') return;
    const trend = @json($data['trend']);
    const categories = @json(array_values($data['categories']));
    const mills = @json($data['mills']);
    const categoryLabels = @json(array_values($labels));
    const colors = @json(array_values($colors));
    const number = value => Number(value).toLocaleString('ms-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const options = {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: item => `${item.dataset.label || item.label}: ${number(item.parsed.y ?? item.parsed)} MT` } }
        },
        scales: { y: { beginAtZero: true, title: { display: true, text: 'MT' }, grid: { color: '#edf1ee' } }, x: { grid: { display: false } } }
    };
    new Chart(document.getElementById('ffb-trend'), {
        type: 'line',
        data: { labels: trend.map(row => row.date), datasets: [{ label: 'BTS Diterima', data: trend.map(row => row.mt), borderColor: '#0b5d32', backgroundColor: '#0b5d3215', fill: true, tension: 0.15, pointRadius: 4, pointHoverRadius: 6 }] },
        options
    });
    const composition = document.getElementById('ffb-composition');
    if (composition) new Chart(composition, {
        type: 'doughnut',
        data: { labels: categoryLabels, datasets: [{ data: categories.map(row => row.mt), backgroundColor: colors, borderWidth: 3, borderColor: '#fff' }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 14, font: { size: 10 } } }, tooltip: { callbacks: { label: item => `${item.label}: ${number(item.parsed)} MT` } } } }
    });
    new Chart(document.getElementById('ffb-mills'), {
        type: 'bar',
        data: { labels: mills.map(row => row.name), datasets: [{ label: 'BTS Diterima', data: mills.map(row => row.mt), backgroundColor: mills.map(row => row.name === 'KBB' ? '#0b5d32' : '#b78916'), borderRadius: 8, maxBarThickness: 64 }] },
        options
    });
})();
</script>
@endsection
