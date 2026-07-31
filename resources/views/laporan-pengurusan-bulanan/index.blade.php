@extends('layouts.app')
@section('title', 'Laporan Prestasi Bulanan Pengurusan')

@section('content')
@php
    $months = collect(range(1, 12))->mapWithKeys(fn ($value) => [
        $value => \Carbon\Carbon::create()->month($value)->translatedFormat('F'),
    ]);
@endphp

<form method="GET" action="{{ route('laporan-pengurusan-bulanan.index') }}" class="bg-white rounded-xl shadow-sm p-4 mb-6 grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Bulan</label>
        <select name="bulan" class="w-full border rounded-lg px-3 py-2 text-sm">
            @foreach($months as $value => $label)
                <option value="{{ $value }}" {{ $month === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tahun</label>
        <select name="tahun" class="w-full border rounded-lg px-3 py-2 text-sm">
            @foreach($years as $optionYear)
                <option value="{{ $optionYear }}" {{ $year === $optionYear ? 'selected' : '' }}>{{ $optionYear }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Skop Kilang</label>
        @if(auth()->user()->canViewAllMills())
            <select name="mill_id" class="w-full border rounded-lg px-3 py-2 text-sm">
                <option value="">Semua Kilang</option>
                @foreach($mills as $mill)
                    <option value="{{ $mill->id }}" {{ (int) request('mill_id') === $mill->id ? 'selected' : '' }}>{{ $mill->name }}</option>
                @endforeach
            </select>
        @else
            <input type="text" value="{{ $mills->first()?->name }}" disabled class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-50">
        @endif
    </div>
    <button class="px-4 py-2 rounded-lg ppnj-green text-white text-sm">Jana Laporan</button>
</form>

<header class="bg-white border-l-4 border-green-700 p-5 mb-6 shadow-sm">
    <p class="text-xs uppercase text-gray-500">{{ $dataset['meta']['title'] }}</p>
    <h1 class="text-xl font-bold text-gray-900 mt-1">{{ strtoupper($dataset['meta']['scope_label']) }}</h1>
    <p class="font-semibold text-green-800">{{ strtoupper($dataset['meta']['period_label']) }}</p>
</header>

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    @foreach([
        'bts_diterima' => ['BTS Diterima', 'MT'],
        'bts_diproses' => ['BTS Diproses', 'MT'],
        'pengeluaran_cpo' => ['Pengeluaran CPO', 'MT'],
        'pengeluaran_pk' => ['Pengeluaran PK', 'MT'],
        'oer' => ['OER', '%'],
        'ker' => ['KER', '%'],
        'throughput' => ['Throughput', 'MT/Jam'],
        'downtime_percentage' => ['Downtime', '%'],
    ] as $key => [$label, $unit])
        <div class="bg-white border rounded-lg p-4">
            <p class="text-xs text-gray-500">{{ $label }}</p>
            <p class="text-xl font-bold text-gray-900 mt-1">
                {{ $dataset['overall']['metrics'][$key] !== null ? number_format((float) $dataset['overall']['metrics'][$key], 2) : 'Tidak Berkenaan' }}
                @if($dataset['overall']['metrics'][$key] !== null)<span class="text-xs font-normal text-gray-500">{{ $unit }}</span>@endif
            </p>
        </div>
    @endforeach
</div>

@foreach($dataset['mills'] as $millReport)
<section class="bg-white border rounded-lg p-5 mb-5">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">{{ $millReport['mill']['name'] }}</h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        @foreach($millReport['executiveCards'] as $card)
            @php $kpi = $card['kpi']; @endphp
            <div class="border rounded-lg p-3" style="border-left: 4px solid {{ $kpi['colour'] ?? '#9CA3AF' }};">
                <p class="text-xs text-gray-500">{{ $card['label'] }}</p>
                <p class="font-bold">{{ $card['actual'] !== null ? number_format((float) $card['actual'], 2) . ' ' . $card['unit'] : 'Tidak Berkenaan' }}</p>
                @if($kpi)
                    <p class="text-xs mt-1" style="color: {{ $kpi['colour'] }};">{{ $kpi['status_label'] === 'Belum Ditetapkan' ? 'KPI Belum Ditetapkan' : $kpi['status_label'] }}</p>
                @endif
            </div>
        @endforeach
    </div>
</section>
@endforeach

@if($dataset['flags']['showMillComparison'])
<section class="bg-white border rounded-lg p-5 mb-5">
    <h2 class="text-lg font-semibold mb-3">Data Perbandingan Kilang</h2>
    <p class="text-sm text-gray-600">Dataset perbandingan tersedia untuk visual Fasa 3B.</p>
</section>
@endif

@if($dataset['flags']['showMpobSection'])
<section class="bg-white border rounded-lg p-5 mb-5">
    <h2 class="text-lg font-semibold mb-3">Konteks Pasaran MPOB Bulanan</h2>
    <p class="text-sm text-gray-600">Data sejarah tempatan tersedia untuk CPO, PK atau CPKO.</p>
</section>
@endif

<script type="application/json" id="management-monthly-report-dataset">@json($dataset)</script>
@endsection
