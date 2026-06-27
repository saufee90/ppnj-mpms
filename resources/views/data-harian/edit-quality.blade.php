@extends('layouts.app')
@section('title', 'Isi Data Kualiti')

@section('content')
<div class="bg-white rounded-xl shadow-sm p-6 max-w-2xl">

    @php
        $oerDisplay = $daily_operation->oer;
        if ($oerDisplay === null) {
            $oerDisplay = ($daily_operation->bts_diproses ?? 0) > 0
                ? round((($daily_operation->produksi_cpo ?? 0) / $daily_operation->bts_diproses) * 100, 2)
                : 0;
        }

        $kerDisplay = $daily_operation->ker;
        if ($kerDisplay === null) {
            $kerDisplay = ($daily_operation->bts_diproses ?? 0) > 0
                ? round((($daily_operation->produksi_pk ?? 0) / $daily_operation->bts_diproses) * 100, 2)
                : 0;
        }
    @endphp

    <div class="mb-4 pb-4 border-b">
        <p class="font-semibold ppnj-green-text">{{ $daily_operation->mill->name }}</p>
        <p class="text-sm text-gray-500">{{ $daily_operation->tarikh->translatedFormat('d F Y') }} &middot; {{ $daily_operation->shift }} &middot; BTS Diproses: {{ number_format($daily_operation->bts_diproses,2) }} MT &middot; CPO: {{ number_format($daily_operation->pengeluaran_cpo,2) }} MT</p>
    </div>

    <form method="POST" action="{{ route('data-harian.update-quality', $daily_operation) }}" class="grid grid-cols-2 gap-4" id="quality-form">
        @csrf @method('PUT')

        <div>
            <label class="block text-xs text-gray-500 mb-1">OER (%)</label>
            <input type="number" step="0.01" value="{{ old('oer', number_format($oerDisplay, 2, '.', '')) }}" readonly disabled class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-100 text-gray-700 cursor-not-allowed">
            <p class="text-xs text-gray-400 mt-1">Auto kira dari Produksi CPO / BTS Diproses.</p>
        </div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">KER (%)</label>
            <input type="number" step="0.01" value="{{ old('ker', number_format($kerDisplay, 2, '.', '')) }}" readonly disabled class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-100 text-gray-700 cursor-not-allowed">
            <p class="text-xs text-gray-400 mt-1">Auto kira dari Produksi PK / BTS Diproses.</p>
        </div>

        @foreach([
            ['ffa','FFA (%) *'],
            ['moisture','Moisture (%) *'],
            ['dirt','Dirt (%) *'],
        ] as [$field, $label])
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ $label }}</label>
            <input type="number" step="0.01" name="{{ $field }}" value="{{ old($field, $daily_operation->$field) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        @endforeach

        <div>
            <label class="block text-xs text-gray-500 mb-1">Throughput (MT/jam)</label>
            <input id="throughput" type="number" step="0.01" name="throughput" value="{{ old('throughput', number_format($daily_operation->computeThroughput(), 2)) }}" readonly class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-100 text-gray-700" data-bts="{{ $daily_operation->bts_diproses }}" data-hours="{{ $daily_operation->jam_operasi }}">
            <p class="text-xs text-gray-400 mt-1">Dikira dari BTS Diproses / Jumlah Jam Proses.</p>
        </div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">Utilisation (%)</label>
            <input id="utilisation_rate" type="text" value="{{ number_format($daily_operation->computeUtilisationRate($daily_operation->computeThroughput()), 2) }}" readonly class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-100 text-gray-700">
        </div>

        <div class="col-span-2 flex justify-end gap-3 pt-4 border-t mt-2">
            <a href="{{ route('data-harian.quality-pending') }}" class="px-4 py-2 rounded-lg border text-sm">Batal</a>
            <button class="px-5 py-2 rounded-lg ppnj-green text-white text-sm font-medium">Simpan Data Kualiti</button>
        </div>
    </form>

    <script>
        (function() {
            const throughputInput = document.getElementById('throughput');
            const utilisationInput = document.getElementById('utilisation_rate');
            const capacity = {{ $daily_operation->mill->code === 'KHG' ? '60' : '30' }};
            const btsDiproses = parseFloat(throughputInput.dataset.bts);
            const jamOperasi = parseFloat(throughputInput.dataset.hours);

            function computeThroughput() {
                if (isNaN(btsDiproses) || isNaN(jamOperasi) || jamOperasi <= 0) {
                    return 0;
                }
                return parseFloat((btsDiproses / jamOperasi).toFixed(2));
            }

            function updateValues() {
                const throughput = computeThroughput();
                throughputInput.value = throughput.toFixed(2);

                if (throughput <= 0 || capacity <= 0) {
                    utilisationInput.value = '0.00';
                    return;
                }

                utilisationInput.value = ((throughput / capacity) * 100).toFixed(2);
            }

            updateValues();
        })();
    </script>
</div>
@endsection
