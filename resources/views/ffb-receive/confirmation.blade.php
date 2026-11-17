@extends('layouts.app')
@section('title', 'FFB Receive - Pengesahan Penerimaan BTS')

@section('content')
@php($presentation = \App\Support\FfbReceivePresentation::class)
<a href="{{ route('ffb-receive.show', $batch) }}" class="mb-4 inline-block text-sm text-green-800 underline">Kembali ke Pratonton</a>
<div class="mb-5 rounded-xl bg-white p-5 shadow-sm">
    <h3 class="font-semibold ppnj-green-text">Ringkasan Pengesahan Batch #{{ $batch->id }}</h3>
    @if(app(\App\Services\FfbDailyReceiptService::class)->isParallelRun($batch->receipt_date->toDateString()))
    <p role="status" class="mt-3 rounded border border-amber-300 bg-amber-50 p-3 font-semibold text-amber-950">
        PARALLEL RUN · REKOD LATIHAN. Batch tidak dipautkan kepada Input Harian dan tidak mengubah KPI operasi.
    </p>
    @endif
    <dl class="mt-4 grid gap-4 text-sm md:grid-cols-2">
        <div><dt class="text-gray-500">Kilang</dt><dd>{{ $batch->mill->code === 'BBJ' ? 'KBB' : $batch->mill->code }} - {{ $batch->mill->name }}</dd></div>
        <div><dt class="text-gray-500">Tarikh Penerimaan</dt><dd>{{ $batch->receipt_date->format('d/m/Y') }}</dd></div>
        <div><dt class="text-gray-500">Jumlah Tiket untuk Pengesahan</dt><dd class="text-lg font-semibold">{{ $summary['ticket_count'] }}</dd></div>
        <div><dt class="text-gray-500">Jumlah BTS Diterima</dt><dd class="text-lg font-semibold">{{ $presentation::mt($summary['total_mt']) }}</dd></div>
    </dl>
    <p class="mt-4 text-sm text-blue-900">Pengesahan ini muktamad dan dikunci, termasuk untuk Admin. Tiada pindaan, pembatalan atau import semula dibenarkan. Mulai 01/12/2026, jumlah disahkan digunakan semasa menghantar Input Harian. Pengesahan tidak mencipta rekod harian atau mengira semula KPI.</p>
</div>
<div class="mb-5 grid gap-4 md:grid-cols-3">
    @foreach($summary['categories'] as $code => $category)
    <div class="rounded-xl bg-white p-4 shadow-sm">
        <h4 class="text-sm font-semibold">{{ $presentation::category($code) }}</h4>
        <p class="mt-2">{{ $category['ticket_count'] }} tiket / {{ $presentation::mt($category['total_mt']) }}</p>
    </div>
    @endforeach
</div>
@if($summary['errors'])
<div role="alert" class="mb-5 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800">
    <h3 class="mb-2 font-semibold">Pengesahan Tidak Dibenarkan</h3>
    @foreach($summary['errors'] as $error)<p>{{ $presentation::message($error) }}</p>@endforeach
    @foreach($summary['issues'] as $issue)
    <details class="mt-2">
        <summary>{{ $issue['worksheet'] }}:{{ $issue['row'] }} - {{ $issue['ticket_number'] ?? 'Tiada nombor tiket' }}</summary>
        @foreach($issue['reasons'] as $reason)<p>{{ $reason }}</p>@endforeach
    </details>
    @endforeach
</div>
@endif
@if($summary['warnings'])
<div class="mb-5 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm">
    <h3 class="mb-2 font-semibold">Amaran untuk Semakan Pegawai</h3>
    @foreach($summary['warnings'] as $warning)<p class="mb-1">{{ $presentation::message($warning) }}</p>@endforeach
</div>
@endif
@if(!$summary['errors'])
<form method="POST" action="{{ route('ffb-receive.confirm', $batch) }}" class="rounded-xl bg-white p-5 shadow-sm">
    @csrf
    <input type="hidden" name="confirmation_token" value="{{ $summary['token'] }}">
    <label class="flex items-start gap-3 text-sm">
        <input type="checkbox" name="acknowledgement" value="1" required class="mt-1">
        <span>Saya telah menyemak tiket, jumlah, kategori dan semua amaran serta mengesahkan penerimaan BTS ini. Saya faham pengesahan ini muktamad dan dikunci serta tiada rekod harian diubah sekarang.</span>
    </label>
    <button type="submit" class="mt-4 rounded-lg ppnj-green px-4 py-2 font-semibold text-white">Sahkan Penerimaan BTS</button>
</form>
@endif
@endsection
