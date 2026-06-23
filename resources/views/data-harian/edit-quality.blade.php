@extends('layouts.app')
@section('title', 'Isi Data Kualiti')

@section('content')
<div class="bg-white rounded-xl shadow-sm p-6 max-w-2xl">

    <div class="mb-4 pb-4 border-b">
        <p class="font-semibold ppnj-green-text">{{ $daily_operation->mill->name }}</p>
        <p class="text-sm text-gray-500">{{ $daily_operation->tarikh->translatedFormat('d F Y') }} &middot; {{ $daily_operation->shift }} &middot; BTS Diproses: {{ number_format($daily_operation->bts_diproses,2) }} MT &middot; CPO: {{ number_format($daily_operation->pengeluaran_cpo,2) }} MT</p>
    </div>

    <form method="POST" action="{{ route('data-harian.update-quality', $daily_operation) }}" class="grid grid-cols-2 gap-4">
        @csrf @method('PUT')

        @foreach([
            ['oer','OER (%) *'],
            ['ker','KER (%) *'],
            ['ffa','FFA (%) *'],
            ['moisture','Moisture (%) *'],
            ['dirt','Dirt (%) *'],
            ['throughput','Throughput (MT/jam) *'],
            ['utilisation_rate','Utilisation (%) *'],
        ] as [$field, $label])
        <div>
            <label class="block text-xs text-gray-500 mb-1">{{ $label }}</label>
            <input type="number" step="0.01" name="{{ $field }}" value="{{ old($field, $daily_operation->$field) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        @endforeach

        <div class="col-span-2 flex justify-end gap-3 pt-4 border-t mt-2">
            <a href="{{ route('data-harian.quality-pending') }}" class="px-4 py-2 rounded-lg border text-sm">Batal</a>
            <button class="px-5 py-2 rounded-lg ppnj-green text-white text-sm font-medium">Simpan Data Kualiti</button>
        </div>
    </form>
</div>
@endsection
