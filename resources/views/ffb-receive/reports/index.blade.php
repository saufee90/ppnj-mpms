@extends('layouts.app')
@section('title', 'Laporan Penerimaan BTS')

@section('content')
@include('ffb-receive.reports.styles')
@php
    $values = array_merge([
        'mill' => '', 'type' => 'hq', 'period' => 'monthly',
        'categories' => ['ppnj_ppk_integrated'],
        'date' => now('Asia/Kuala_Lumpur')->toDateString(),
        'month' => now('Asia/Kuala_Lumpur')->format('Y-m'),
        'year' => now('Asia/Kuala_Lumpur')->year,
        'start' => now('Asia/Kuala_Lumpur')->startOfMonth()->toDateString(),
        'end' => now('Asia/Kuala_Lumpur')->toDateString(),
    ], $filters ?? [], $input);
@endphp
<div class="space-y-6">
    <header class="rounded-2xl bg-green-900 px-6 py-7 text-white border-b-4 border-yellow-500">
        <p class="text-xs uppercase tracking-widest text-yellow-200">Pertubuhan Peladang Negeri Johor</p>
        <h1 class="mt-2 text-2xl font-bold">Laporan Penerimaan BTS</h1>
        <p class="mt-2 text-sm text-green-100">Ringkasan HQ dan eksport Daily FFB Quality Report.</p>
    </header>
    <form method="GET" action="{{ route('ffb-receive.reports.index') }}" id="bts-report-filters" class="rounded-xl border bg-white p-5 shadow-sm">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <label class="text-sm font-semibold">Kilang <span class="text-red-600">*</span>
                <select required name="mill" class="mt-2 w-full rounded-lg border p-3">
                    <option value="">Pilih satu kilang</option>
                    @foreach($mills as $mill)
                    <option value="{{ $mill->code }}" @selected($values['mill'] === $mill->code)>{{ $mill->code === 'BBJ' ? 'Kilang Sawit Bukit Bujang (KBB)' : 'Kilang Sawit Kahang (KHG)' }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold">Tempoh
                <select name="period" id="bts-report-period" class="mt-2 w-full rounded-lg border p-3">
                    @foreach(['daily' => 'Harian', 'monthly' => 'Bulanan', 'yearly' => 'Tahunan', 'range' => 'Julat Tarikh'] as $value => $label)
                    <option value="{{ $value }}" @selected($values['period'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm font-semibold" data-report-period="daily">Tarikh
                <input required type="date" name="date" min="1000-01-01" value="{{ $values['date'] }}" class="mt-2 w-full rounded-lg border p-3">
            </label>
            <label class="text-sm font-semibold" data-report-period="monthly">Bulan
                <input required type="month" name="month" min="1000-01" value="{{ $values['month'] }}" class="mt-2 w-full rounded-lg border p-3">
            </label>
            <label class="text-sm font-semibold" data-report-period="yearly">Tahun
                <input required type="number" name="year" min="1000" max="9999" value="{{ $values['year'] }}" class="mt-2 w-full rounded-lg border p-3">
            </label>
            <label class="text-sm font-semibold" data-report-period="range">Tarikh Mula
                <input required type="date" name="start" min="1000-01-01" value="{{ $values['start'] }}" class="mt-2 w-full rounded-lg border p-3">
            </label>
            <label class="text-sm font-semibold" data-report-period="range">Tarikh Akhir
                <input required type="date" name="end" min="1000-01-01" value="{{ $values['end'] }}" class="mt-2 w-full rounded-lg border p-3">
            </label>
        </div>
        <fieldset class="mt-4 rounded-lg border p-3">
            <legend class="px-2 text-sm font-semibold">Kategori BTS (pilih sekurang-kurangnya satu)</legend>
            <input type="hidden" name="categories" value="">
            <div class="flex flex-wrap gap-4">
                @foreach(['ppnj_ppk_integrated', 'ppnj_owned', 'external_supplier'] as $key)
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="categories[]" value="{{ $key }}" @checked(in_array($key, $values['categories'], true))>
                    {{ \App\Support\FfbReceivePresentation::category($key) }}
                </label>
                @endforeach
            </div>
        </fieldset>
        <div class="mt-5 flex flex-wrap items-center gap-3">
            <button class="rounded-lg bg-green-800 px-5 py-3 text-sm font-semibold text-white">Papar Laporan</button>
            @if($report)
            <a href="{{ route('ffb-receive.reports.pdf', array_merge($filters, ['type' => $report['type']])) }}" class="rounded-lg border border-green-800 px-5 py-3 text-sm font-semibold text-green-900">Jana PDF</a>
            <a href="{{ route('ffb-receive.reports.excel', array_merge($filters, ['type' => $report['type']])) }}" class="rounded-lg border border-yellow-600 px-5 py-3 text-sm font-semibold text-yellow-900">Eksport Excel (.xlsx)</a>
            @endif
        </div>
        <p class="mt-3 text-xs text-gray-500">Excel mengikut kategori dipilih. Nilai kualiti asal dikekalkan; data tiada dibiarkan kosong dan isu pemetaan dinyatakan dalam eksport.</p>
    </form>
    @if($report)
        @include('ffb-receive.reports.content')
    @else
        <div class="rounded-xl border border-green-200 bg-green-50 p-6 text-sm text-green-900">Pilih satu kilang secara jelas untuk menjana laporan. Data KBB dan Kahang tidak digabung dalam laporan ini.</div>
    @endif
</div>
<script>
(() => {
    const form = document.getElementById('bts-report-filters');
    const period = document.getElementById('bts-report-period');
    const update = () => form.querySelectorAll('[data-report-period]').forEach(label => {
        const active = label.dataset.reportPeriod === period.value;
        label.hidden = !active;
        label.querySelectorAll('input').forEach(input => input.disabled = !active);
    });
    period.addEventListener('change', update);
    form.addEventListener('submit', event => {
        if (!form.querySelector('input[name="categories[]"]:checked')) {
            event.preventDefault();
            alert('Sila pilih sekurang-kurangnya satu kategori BTS.');
        }
    });
    update();
})();
</script>
@endsection
