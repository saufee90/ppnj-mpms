@extends('layouts.app')
@section('title', 'Butiran Rekod Harian')

@section('content')
<div class="bg-white rounded-xl shadow-sm p-6 max-w-4xl space-y-6">

    <div class="flex justify-between items-start border-b pb-4">
        <div>
            <h3 class="text-lg font-bold ppnj-green-text">{{ $daily_operation->mill->name }}</h3>
            <p class="text-sm text-gray-500">{{ $daily_operation->tarikh->translatedFormat('d F Y') }} &middot; {{ $daily_operation->shift }}</p>
        </div>
        <a href="{{ route('rekod-harian.index') }}" class="text-sm text-blue-600">&larr; Kembali</a>
    </div>

    @php
        $groups = [
            'Penerimaan & Pemprosesan' => [
                'BTS Diterima (MT)' => number_format($daily_operation->bts_diterima,2),
                'BTS Diproses (MT)' => number_format($daily_operation->bts_diproses,2),
                'Baki Stok BTS (MT)' => number_format($daily_operation->baki_stok_bts,2),
                'Jam Operasi' => number_format($daily_operation->jam_operasi,2),
                'Downtime (jam)' => number_format($daily_operation->downtime_jam,2),
            ],
            'Pengeluaran' => [
                'CPO (MT)' => number_format($daily_operation->pengeluaran_cpo,2),
                'PK (MT)' => number_format($daily_operation->pengeluaran_pk,2),
                'Stok CPO (MT)' => number_format($daily_operation->stok_cpo,2),
                'Stok PK (MT)' => number_format($daily_operation->stok_pk,2),
            ],
            'Kualiti & KPI (Diisi T+1 oleh Pegawai Kilang)' => [
                'OER (%)' => $daily_operation->oer !== null ? number_format($daily_operation->oer,2) : 'Belum diisi',
                'KER (%)' => $daily_operation->ker !== null ? number_format($daily_operation->ker,2) : 'Belum diisi',
                'FFA (%)' => $daily_operation->ffa !== null ? number_format($daily_operation->ffa,2) : 'Belum diisi',
                'Moisture (%)' => $daily_operation->moisture !== null ? number_format($daily_operation->moisture,2) : 'Belum diisi',
                'Dirt (%)' => $daily_operation->dirt !== null ? number_format($daily_operation->dirt,2) : 'Belum diisi',
                'Throughput (MT/jam)' => $daily_operation->throughput !== null ? number_format($daily_operation->throughput,2) : 'Belum diisi',
                'Utilisation (%)' => $daily_operation->utilisation_rate !== null ? number_format($daily_operation->utilisation_rate,2) : 'Belum diisi',
            ],
        ];
    @endphp

    @foreach($groups as $title => $fields)
    <div>
        <h4 class="text-sm font-semibold text-gray-600 mb-2">{{ $title }}</h4>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach($fields as $label => $value)
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-400">{{ $label }}</p>
                <p class="font-semibold">{{ $value }}</p>
            </div>
            @endforeach
        </div>
    </div>
    @endforeach

    <div>
        <h4 class="text-sm font-semibold text-gray-600 mb-2">Catatan</h4>
        <p class="text-sm text-gray-600"><strong>Sebab Downtime:</strong> {{ $daily_operation->sebab_downtime ?: '-' }}</p>
        <p class="text-sm text-gray-600"><strong>Isu Operasi:</strong> {{ $daily_operation->isu_operasi ?: '-' }}</p>
        <p class="text-sm text-gray-600"><strong>Tindakan Pembetulan:</strong> {{ $daily_operation->tindakan_pembetulan ?: '-' }}</p>
        <p class="text-sm text-gray-600"><strong>Catatan Tambahan:</strong> {{ $daily_operation->catatan_tambahan ?: '-' }}</p>
    </div>

    <div class="text-xs text-gray-400 border-t pt-3">
        Dikemaskini oleh {{ $daily_operation->officer->name ?? '-' }} pada {{ $daily_operation->updated_at->format('d/m/Y H:i') }}
    </div>
</div>
@endsection
