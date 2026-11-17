@extends('layouts.app')
@section('title', 'Sahkan Pembetulan FFB')

@section('content')
@php($presentation = \App\Support\FfbReceivePresentation::class)
<a href="{{ route('ffb-receive.show', $predecessor) }}" class="mb-4 inline-block text-sm text-green-800 underline">Kembali ke Sejarah Batch</a>
<div class="mb-5 rounded-xl bg-white p-5 shadow-sm">
    <h2 class="text-xl font-semibold ppnj-green-text">Perbandingan Sebelum dan Selepas</h2>
    <p class="mt-2 text-sm">Batch #{{ $predecessor->id }} → Calon versi baharu #{{ $candidate->id }}</p>
    <p class="text-sm">{{ $candidate->mill->code === 'BBJ' ? 'KBB' : $candidate->mill->code }} · {{ $candidate->receipt_date->format('d/m/Y') }}</p>
    @if(app(\App\Services\FfbDailyReceiptService::class)->isParallelRun($candidate->receipt_date->toDateString()))
    <p role="status" class="mt-3 rounded border border-amber-300 bg-amber-50 p-3 font-semibold text-amber-950">
        PARALLEL RUN · PEMBETULAN LATIHAN. Tiada Daily Operation atau KPI rasmi akan diubah.
    </p>
    @endif
    <p class="mt-3 text-sm"><strong>Sebab Pembetulan:</strong> {{ $candidate->confirmation_reason }}</p>
    <p class="mt-1 text-sm"><strong>Disediakan oleh:</strong> {{ $candidate->uploader?->name ?? 'Pengguna #'.$candidate->uploaded_by }}</p>
</div>

@if($summary['comparison'] ?? false)
<div class="mb-5 grid gap-4 md:grid-cols-2">
    @foreach(['before' => 'Sebelum', 'after' => 'Selepas'] as $side => $label)
    @php($data = $summary['comparison'][$side])
    <section class="rounded-xl bg-white p-5 shadow-sm">
        <h3 class="font-semibold">{{ $label }} · Batch #{{ $data['batch_id'] }}</h3>
        <p class="mt-2">{{ $data['ticket_count'] }} tiket / {{ $presentation::mt($data['total_mt']) }} MT</p>
        <h4 class="mt-4 text-sm font-semibold">Kategori Sumber</h4>
        @forelse($data['categories'] as $category => $totals)
        <p class="text-sm">{{ $presentation::category($category) }}: {{ $totals['ticket_count'] }} / {{ $presentation::mt($totals['total_mt']) }} MT</p>
        @empty
        <p class="text-sm">Tiada tiket Excel.</p>
        @endforelse
        <h4 class="mt-4 text-sm font-semibold">PPK / Sumber</h4>
        @forelse($data['ppk'] as $source => $totals)
        <p class="text-sm">{{ $source }}: {{ $totals['ticket_count'] }} / {{ $presentation::mt($totals['total_mt']) }} MT</p>
        @empty
        <p class="text-sm">Tiada pecahan PPK.</p>
        @endforelse
    </section>
    @endforeach
</div>
<section class="mb-5 rounded-xl bg-white p-5 shadow-sm">
    <h3 class="font-semibold">Perubahan Tiket (setakat nombor tiket)</h3>
    <p class="mt-2 text-sm">Ditambah: {{ count($summary['comparison']['tickets']['added']) }}</p>
    <p class="text-sm">Dibuang: {{ count($summary['comparison']['tickets']['removed']) }}</p>
    <p class="text-sm">Berubah: {{ count($summary['comparison']['tickets']['changed']) }}</p>
    @foreach(['added' => 'Tiket ditambah', 'removed' => 'Tiket dibuang', 'changed' => 'Tiket berubah'] as $key => $label)
        @if($summary['comparison']['tickets'][$key])
        <details class="mt-2 text-sm">
            <summary class="cursor-pointer">{{ $label }}</summary>
            <p class="mt-1 break-all">{{ implode(', ', $summary['comparison']['tickets'][$key]) }}</p>
        </details>
        @endif
    @endforeach
</section>
@endif

@if($summary['errors'])
<div role="alert" class="mb-5 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800">
    <h3 class="font-semibold">Pembetulan Tidak Boleh Disahkan</h3>
    @foreach($summary['errors'] as $error)<p>{{ $presentation::message($error) }}</p>@endforeach
</div>
@endif
@if($summary['warnings'] ?? [])
<div class="mb-5 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm">
    @foreach($summary['warnings'] as $warning)<p>{{ $presentation::message($warning) }}</p>@endforeach
</div>
@endif

@if(!$summary['errors'])
<form method="POST" action="{{ route('ffb-receive.correction.confirm', $candidate) }}" class="rounded-xl bg-white p-5 shadow-sm">
    @csrf
    <input type="hidden" name="confirmation_token" value="{{ $summary['token'] }}">
    <label class="flex items-start gap-3 text-sm">
        <input type="checkbox" name="acknowledgement" value="1" required class="mt-1">
        <span>Saya telah menyemak perbandingan sebelum dan selepas, sebab pembetulan dan jumlah BTS. Saya mengesahkan versi baharu ini.</span>
    </label>
    <button class="mt-4 rounded-lg ppnj-green px-4 py-2 font-semibold text-white">Sahkan Pembetulan</button>
</form>
@endif
@endsection
