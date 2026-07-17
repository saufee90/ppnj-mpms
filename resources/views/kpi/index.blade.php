@extends('layouts.app')
@section('title', 'Tetapan KPI')

@section('content')
@php
    $monthNames = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Mac',
        4 => 'April',
        5 => 'Mei',
        6 => 'Jun',
        7 => 'Julai',
        8 => 'Ogos',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Disember',
    ];

    $periodTargetLabel = $selectedYear === 2026
        ? 'Jumlah Sasaran Julai-Disember 2026 (MT)'
        : 'Jumlah Sasaran Tahunan (MT)';

    $selectedScopeLabel = '';
    foreach ($mills as $mill) {
        if ((string) $mill['id'] === (string) $selectedScope) {
            $selectedScopeLabel = $mill['label'];
            break;
        }
    }

    $selectedPeriodLabel = $selectedYear === 2026
        ? 'Julai-Disember 2026'
        : 'Januari-Disember ' . $selectedYear;

    $hasFormErrors = $errors->any();

    $sectionDescriptions = [
        'Penerimaan dan Pemprosesan BTS' => 'Tetapan KPI penerimaan dan pemprosesan BTS bagi operasi harian.',
        'Prestasi Pengeluaran' => 'Tetapan KPI pengeluaran CPO/PK dan kualiti prestasi kilang.',
        'Stok dan Downtime' => 'Tetapan KPI kawalan stok dan masa henti operasi kilang.',
        'Jualan Berbanding Pengeluaran' => 'Tetapan KPI perbandingan jualan dengan jumlah pengeluaran.',
    ];
@endphp

<div class="bg-white rounded-2xl shadow-sm p-6 md:p-8 mb-6">
    <h2 class="text-xl font-semibold ppnj-green-text mb-5">Tetapan KPI</h2>

    <form id="kpi-filter-form" method="GET" action="{{ route('kpi.index') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Pilih Kilang</label>
            <select id="kpi-scope" name="scope" class="w-full border rounded-xl px-4 py-3 text-base">
                @foreach($mills as $mill)
                    <option value="{{ $mill['id'] }}" {{ (string)$selectedScope === (string)$mill['id'] ? 'selected' : '' }}>{{ $mill['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Pilih Tahun</label>
            <input id="kpi-year" type="number" name="year" min="2026" max="2100" value="{{ $selectedYear }}" class="w-full border rounded-xl px-4 py-3 text-base">
        </div>
    </form>

    <div class="mt-5 rounded-xl border border-emerald-100 bg-emerald-50 p-4">
        <p class="text-base font-semibold text-emerald-800">Tetapan KPI: {{ $selectedScopeLabel }}</p>
        <p class="text-sm text-emerald-700 mt-1">Tempoh: {{ $selectedPeriodLabel }}</p>
        @if($selectedYear === 2026)
            <p class="text-sm text-emerald-700 mt-2">Data dan pengiraan KPI tahun 2026 bermula pada 1 Julai 2026.</p>
        @endif
    </div>

    <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="rounded-xl border border-green-200 bg-green-50 p-3">
            <p class="text-sm font-semibold text-green-800">HIJAU - Prestasi baik</p>
        </div>
        <div class="rounded-xl border border-yellow-200 bg-yellow-50 p-3">
            <p class="text-sm font-semibold text-yellow-800">KUNING - Perlu perhatian</p>
        </div>
        <div class="rounded-xl border border-red-200 bg-red-50 p-3">
            <p class="text-sm font-semibold text-red-800">MERAH - Kritikal</p>
        </div>
    </div>
    <p class="text-sm text-gray-700 mt-3">Masukkan paras Hijau dan paras Merah. Sistem akan menentukan status Kuning secara automatik bagi nilai di antara kedua-dua paras.</p>
    <p class="text-sm text-gray-500 mt-1">Kelabu bermaksud sasaran belum ditetapkan atau data belum tersedia.</p>

    <details class="mt-4 rounded-xl border p-4 bg-gray-50">
        <summary class="cursor-pointer text-sm font-semibold text-gray-800">Lihat Contoh</summary>
        <div class="mt-3 text-sm text-gray-700 space-y-2">
            <p>Contoh BTS Diproses: Hijau 10,800 MT, Merah 8,640 MT. Nilai antara kedua-duanya akan ditandakan Kuning.</p>
            <p>Contoh Downtime: Hijau 2 Jam, Merah 5 Jam. Downtime 3 Jam akan ditandakan Kuning.</p>
        </div>
    </details>
</div>

<form id="kpi-settings-form" method="POST" action="{{ route('kpi.store') }}" class="space-y-5 pb-28">
    @csrf
    <input type="hidden" name="scope" value="{{ $selectedScope }}">
    <input type="hidden" name="year" value="{{ $selectedYear }}">

    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
        <p class="text-sm text-blue-900">Paras bulanan dinilai dalam MT. Bagi laporan pertengahan bulan, sistem melaraskan paras mengikut bilangan hari yang telah berlalu.</p>
    </div>

    @foreach($settingsBySection as $sectionName => $indicators)
        <details class="bg-white rounded-2xl shadow-sm border" {{ $loop->first ? 'open' : '' }}>
            <summary class="cursor-pointer px-5 py-4">
                <h3 class="text-lg font-semibold ppnj-green-text">{{ $sectionName }}</h3>
                <p class="text-sm text-gray-600 mt-1">{{ $sectionDescriptions[$sectionName] ?? 'Tetapan indikator KPI mengikut kategori.' }}</p>
            </summary>

            <div class="px-5 pb-5 space-y-4">
                @foreach($indicators as $indicator)
                    <div class="rounded-xl border p-4 bg-gray-50">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-3">
                            <div>
                                <p class="text-base md:text-lg font-semibold text-gray-800">{{ $indicator['name'] }}</p>
                                <p class="text-sm text-gray-600">Unit: {{ $indicator['unit'] }}</p>
                            </div>
                            <label class="inline-flex items-center gap-3 text-sm font-medium text-gray-700">
                                <input type="hidden" name="settings[{{ $indicator['code'] }}][is_active]" value="0">
                                <input type="checkbox" name="settings[{{ $indicator['code'] }}][is_active]" value="1" {{ old('settings.'.$indicator['code'].'.is_active', $indicator['is_active']) ? 'checked' : '' }} class="w-5 h-5 rounded border-gray-400">
                                Gunakan KPI ini
                            </label>
                        </div>

                        @if($indicator['evaluation_basis'] === 'monthly_flow')
                            <div class="mb-3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">{{ $periodTargetLabel }}</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    name="settings[{{ $indicator['code'] }}][period_target]"
                                    value="{{ old('settings.'.$indicator['code'].'.period_target', $indicator['period_target']) }}"
                                    class="w-full md:w-1/2 border rounded-xl px-4 py-3 text-base {{ $hasFormErrors ? 'border-red-300' : '' }}"
                                >
                                <p class="text-sm text-gray-500 mt-1">Untuk rujukan keseluruhan tempoh.</p>
                            </div>

                            <div class="rounded-xl border overflow-hidden bg-white">
                                <div class="hidden md:grid md:grid-cols-3 text-sm">
                                    <div class="px-4 py-3 border-b font-semibold">Bulan</div>
                                    <div class="px-4 py-3 border-b font-semibold bg-green-50 text-green-800">Paras Hijau (MT)</div>
                                    <div class="px-4 py-3 border-b font-semibold bg-red-50 text-red-800">Paras Merah (MT)</div>
                                </div>

                                @foreach($applicableMonths as $m)
                                    @php
                                        $existing = $indicator['monthly_targets'][(string) $m] ?? $indicator['monthly_targets'][$m] ?? [];
                                        $existingGreen = is_array($existing) ? ($existing['green'] ?? null) : null;
                                        $existingRed = is_array($existing) ? ($existing['red'] ?? null) : null;
                                    @endphp
                                    <div class="grid grid-cols-1 md:grid-cols-3 border-b last:border-b-0">
                                        <div class="px-4 py-3 font-medium text-gray-800">{{ $monthNames[$m] }}</div>

                                        <div class="px-4 py-3 bg-green-50">
                                            <label class="block md:sr-only text-sm text-green-800 mb-1">Paras Hijau (MT)</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                name="settings[{{ $indicator['code'] }}][monthly_targets][{{ $m }}][green]"
                                                value="{{ old('settings.'.$indicator['code'].'.monthly_targets.'.$m.'.green', $existingGreen) }}"
                                                class="w-full border border-green-200 rounded-lg px-3 py-2 text-base bg-white {{ $hasFormErrors ? 'border-red-300' : '' }}"
                                            >
                                        </div>

                                        <div class="px-4 py-3 bg-red-50">
                                            <label class="block md:sr-only text-sm text-red-800 mb-1">Paras Merah (MT)</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                name="settings[{{ $indicator['code'] }}][monthly_targets][{{ $m }}][red]"
                                                value="{{ old('settings.'.$indicator['code'].'.monthly_targets.'.$m.'.red', $existingRed) }}"
                                                class="w-full border border-red-200 rounded-lg px-3 py-2 text-base bg-white {{ $hasFormErrors ? 'border-red-300' : '' }}"
                                            >
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="mb-3">
                                @if($indicator['direction'] === 'higher_is_better')
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-emerald-100 text-emerald-800">Lebih tinggi lebih baik</span>
                                @else
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-amber-100 text-amber-800">Lebih rendah lebih baik</span>
                                @endif
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Paras Hijau ({{ $indicator['unit'] }})</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        name="settings[{{ $indicator['code'] }}][green_threshold]"
                                        value="{{ old('settings.'.$indicator['code'].'.green_threshold', $indicator['green_threshold']) }}"
                                        class="w-full border rounded-xl px-4 py-3 text-base {{ $hasFormErrors ? 'border-red-300' : '' }}"
                                    >
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Paras Merah ({{ $indicator['unit'] }})</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        name="settings[{{ $indicator['code'] }}][red_threshold]"
                                        value="{{ old('settings.'.$indicator['code'].'.red_threshold', $indicator['red_threshold']) }}"
                                        class="w-full border rounded-xl px-4 py-3 text-base {{ $hasFormErrors ? 'border-red-300' : '' }}"
                                    >
                                </div>
                            </div>

                            <div class="mt-3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Sasaran Tempoh (Opsyenal, {{ $indicator['unit'] }})</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    name="settings[{{ $indicator['code'] }}][period_target]"
                                    value="{{ old('settings.'.$indicator['code'].'.period_target', $indicator['period_target']) }}"
                                    class="w-full md:w-1/2 border rounded-xl px-4 py-3 text-base {{ $hasFormErrors ? 'border-red-300' : '' }}"
                                >
                                <p class="text-sm text-gray-500 mt-1">Untuk rujukan keseluruhan tempoh.</p>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </details>
    @endforeach

    <details class="bg-white rounded-2xl shadow-sm border">
        <summary class="cursor-pointer px-5 py-4">
            <h3 class="text-lg font-semibold ppnj-green-text">Rekod KPI Lama</h3>
            <p class="text-sm text-gray-600 mt-1">Rekod ini dikekalkan untuk kegunaan fungsi lama MPS dan tidak perlu dikemas kini di halaman ini.</p>
        </summary>

        <div class="px-5 pb-5 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-3 text-left">Kilang</th>
                        <th class="px-4 py-3 text-center">Tahun</th>
                        <th class="px-4 py-3 text-right">OER%</th>
                        <th class="px-4 py-3 text-right">KER%</th>
                        <th class="px-4 py-3 text-right">FFA%</th>
                        <th class="px-4 py-3 text-right">Downtime Max (Jam)</th>
                        <th class="px-4 py-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($legacyTargets as $target)
                        @php
                            $legacyScope = 'Global';
                            if ($target->mill) {
                                $legacyScope = match($target->mill->code) {
                                    'BBJ' => 'KBB',
                                    'KHG' => 'KKHG',
                                    default => $target->mill->code,
                                };
                                $legacyScope .= ' - ' . $target->mill->name;
                            }
                        @endphp
                        <tr>
                            <td class="px-4 py-3">{{ $legacyScope }}</td>
                            <td class="px-4 py-3 text-center">{{ $target->effective_year }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($target->oer_target, 2) }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($target->ker_target, 2) }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($target->ffa_max, 2) }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($target->downtime_max_hours, 2) }}</td>
                            <td class="px-4 py-3 text-center">{{ $target->is_active ? 'Aktif' : 'Tidak Aktif' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-gray-400">Tiada rekod KPI legacy.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </details>

    <div class="fixed bottom-0 left-0 right-0 md:left-64 border-t bg-white/95 backdrop-blur px-4 py-3 z-20">
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <p class="text-sm text-gray-700">Anda sedang menyimpan tetapan untuk {{ $selectedScopeLabel }}, Tahun {{ $selectedYear }}.</p>
            <button type="submit" class="w-full md:w-auto px-6 py-3 rounded-xl ppnj-green text-white text-base font-semibold">Simpan Semua Tetapan KPI</button>
        </div>
    </div>
</form>
@endsection

@section('scripts')
<script>
(() => {
    const kpiForm = document.getElementById('kpi-settings-form');
    const filterForm = document.getElementById('kpi-filter-form');
    const scopeField = document.getElementById('kpi-scope');
    const yearField = document.getElementById('kpi-year');

    if (!kpiForm || !filterForm || !scopeField || !yearField) {
        return;
    }

    let isDirty = false;
    let isSubmitting = false;

    kpiForm.querySelectorAll('input, select, textarea').forEach((field) => {
        field.addEventListener('change', () => {
            isDirty = true;
        });
    });

    kpiForm.addEventListener('submit', () => {
        isSubmitting = true;
        isDirty = false;
    });

    const confirmIfDirtyThen = (event, nextAction) => {
        if (!isDirty || isSubmitting) {
            nextAction();
            return;
        }

        const proceed = window.confirm('Perubahan KPI belum disimpan. Teruskan tanpa simpan?');
        if (!proceed) {
            event.preventDefault();
            return;
        }

        isDirty = false;
        nextAction();
    };

    scopeField.addEventListener('change', (event) => {
        confirmIfDirtyThen(event, () => filterForm.submit());
    });

    yearField.addEventListener('change', (event) => {
        confirmIfDirtyThen(event, () => filterForm.submit());
    });

    window.addEventListener('beforeunload', (event) => {
        if (!isDirty || isSubmitting) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });
})();
</script>
@endsection
