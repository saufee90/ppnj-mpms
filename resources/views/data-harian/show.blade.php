@extends('layouts.app')
@section('title', 'Butiran Rekod Harian')

@section('content')
<div class="bg-white rounded-xl shadow-sm p-6 max-w-4xl space-y-6">

    @php
        $canUpdate = auth()->user()->canEditData() && auth()->user()->can('update', $daily_operation);
        $isPegawai = auth()->user()->isPegawaiKilang();
        $editDeadline = $daily_operation->amendmentEditableUntil()->translatedFormat('d F Y');
    @endphp

    <div class="flex justify-between items-start border-b pb-4">
        <div>
            <h3 class="text-lg font-bold ppnj-green-text">{{ $daily_operation->mill->name }}</h3>
            <p class="text-sm text-gray-500">{{ $daily_operation->tarikh->translatedFormat('d F Y') }} &middot; {{ $daily_operation->shift }}</p>
            @if($isPegawai)
                <p class="text-xs text-gray-500 mt-1">Boleh dipinda sehingga: {{ $editDeadline }}</p>
            @endif
        </div>
        <div class="flex items-center gap-3">
            @if($canUpdate)
                <a href="{{ route('data-harian.edit', $daily_operation) }}" class="text-sm px-3 py-1.5 rounded-lg ppnj-green text-white">Edit Rekod</a>
            @elseif($isPegawai && $daily_operation->mill_id === auth()->user()->mill_id)
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-red-100 text-red-700">Tempoh pindaan tamat</span>
            @endif
            <a href="{{ route('rekod-harian.index') }}" class="text-sm text-blue-600">&larr; Kembali</a>
        </div>
    </div>

    @php
        $isBbj = ($daily_operation->mill->code ?? null) === 'BBJ';

        $groups = [
            'Penerimaan & Pemprosesan' => [
                'BTS Diterima (MT)' => number_format($daily_operation->bts_diterima,2),
                'BTS Diproses (MT)' => number_format($daily_operation->bts_diproses,2),
                'Baki BTS Semalam (MT)' => $daily_operation->baki_bts_semalam !== null ? number_format($daily_operation->baki_bts_semalam,2) : '-',
                'Baki BTS Selepas Diproses (MT)' => $daily_operation->baki_bts_selepas_diproses !== null ? number_format($daily_operation->baki_bts_selepas_diproses,2) : '-',
                'Jam Operasi' => number_format($daily_operation->jam_operasi,2),
                'Downtime (jam)' => number_format($daily_operation->downtime_jam,2),
            ],
            'Pengeluaran' => [
                'Jualan CPO (MT)' => number_format($daily_operation->pengeluaran_cpo,2),
                $isBbj ? 'Jualan PK kepada Pembeli Luar (MT)' : 'Jualan PK (MT)' => number_format($daily_operation->pengeluaran_pk,2),
                $isBbj ? 'PK KCP to Hopper (MT)' : '__skip_pk_hopper__' => $isBbj ? number_format($daily_operation->pk_kcp_to_hopper ?? 0, 2) : null,
                'Pengeluaran CPO (MT)' => $daily_operation->produksi_cpo !== null ? number_format($daily_operation->produksi_cpo,2) : '-',
                'Pengeluaran PK (MT)' => $daily_operation->produksi_pk !== null ? number_format($daily_operation->produksi_pk,2) : '-',
                'Stok CPO Semalam (MT)' => $daily_operation->stok_cpo_yesterday !== null ? number_format($daily_operation->stok_cpo_yesterday,2) : '-',
                $isBbj ? 'Stok PK KCP Semalam (MT)' : 'Stok PK Semalam (MT)' => $daily_operation->stok_pk_yesterday !== null ? number_format($daily_operation->stok_pk_yesterday,2) : '-',
                'Stok CPO (MT)' => number_format($daily_operation->stok_cpo,2),
                $isBbj ? 'Stok PK KCP (MT)' : 'Stok PK (MT)' => number_format($daily_operation->stok_pk,2),
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
            @if($label !== '__skip_pk_hopper__')
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs text-gray-400">{{ $label }}</p>
                <p class="font-semibold">{{ $value }}</p>
            </div>
            @endif
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
        @if($daily_operation->last_amendment_reason)
            <p class="mt-2 text-gray-600"><strong>Sebab Pindaan Terakhir:</strong> {{ $daily_operation->last_amendment_reason }}</p>
            <p class="text-gray-500">Dipinda pada {{ optional($daily_operation->last_amended_at)->format('d/m/Y H:i') }} oleh {{ optional($daily_operation->lastAmendedBy)->name ?? '-' }}</p>
        @endif
    </div>
</div>
@endsection
