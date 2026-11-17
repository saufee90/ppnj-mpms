@php($data = $report['data'])
<article class="bts-report">
    <header class="report-cover">
        <p class="eyebrow">Pertubuhan Peladang Negeri Johor</p>
        <h1>Laporan Penerimaan BTS</h1>
        <p><strong>{{ $report['mill_name'] }}</strong></p>
        <p>{{ $report['filters']['start'] }} &ndash; {{ $report['filters']['end'] }} &middot; Dijana {{ $report['generated_at'] }}</p>
        <p>{{ $report['category_label'] }}</p>
        <span class="badge">Disahkan sahaja</span>
        @if($report['parallel_run_notice'])
        <p class="mt-3 rounded border border-amber-300 bg-amber-50 p-3 font-semibold text-amber-950">
            PARALLEL RUN (05/11/2026–30/11/2026): rekod latihan, bukan data KPI operasi rasmi.
        </p>
        @endif
    </header>
    <section class="report-section">
        <table class="kpi-table"><tbody><tr>
            <td>Jumlah BTS<span class="kpi-value">{{ number_format($data['total_mt'], 2) }}</span>MT</td>
            <td>Tiket Timbang<span class="kpi-value">{{ $data['ticket_count'] }}</span>Tiket sebenar</td>
            <td>Hari Disahkan Kilang<span class="kpi-value">{{ $data['confirmed_days'] }}</span>Termasuk {{ $data['zero_mill_days'] }} hari sifar (semua kategori)</td>
        </tr></tbody></table>
        @if(!$data['has_data'])<p>Tiada penerimaan disahkan dalam tempoh dipilih.</p>@endif
    </section>
    <section class="report-section">
        <h2>Jumlah Mengikut Kategori</h2>
        <div class="table-scroll"><table>
            <thead><tr><th>Kategori</th><th class="num">MT</th><th class="num">Tiket</th><th class="num">%</th></tr></thead>
            <tbody>
                @foreach($data['categories'] as $category)
                <tr><td>{{ $category['name'] }}</td><td class="num">{{ number_format($category['mt'], 2) }}</td><td class="num">{{ $category['tickets'] }}</td><td class="num">{{ number_format($category['percentage'], 2) }}</td></tr>
                @endforeach
                <tr class="total"><td>Jumlah</td><td class="num">{{ number_format($data['total_mt'], 2) }}</td><td class="num">{{ $data['ticket_count'] }}</td><td class="num">{{ $data['total_mt'] > 0 ? '100.00' : '—' }}</td></tr>
            </tbody>
        </table></div>
    </section>
    <section class="report-section">
        <h2>Jumlah Mengikut Sumber</h2>
        <div class="table-scroll"><table>
            <thead><tr><th>Sumber</th><th>Kategori</th><th class="num">MT</th><th class="num">Tiket</th></tr></thead>
            <tbody>
                @forelse($data['sources'] as $source)
                <tr><td>{{ $source['name'] }}</td><td>{{ \App\Support\FfbReceivePresentation::category($source['category']) }}</td><td class="num">{{ number_format($source['mt'], 2) }}</td><td class="num">{{ $source['tickets'] }}</td></tr>
                @empty
                <tr><td colspan="4">Tiada tiket timbang.</td></tr>
                @endforelse
                <tr class="total"><td colspan="2">Jumlah</td><td class="num">{{ number_format($data['total_mt'], 2) }}</td><td class="num">{{ $data['ticket_count'] }}</td></tr>
            </tbody>
        </table></div>
    </section>
</article>
