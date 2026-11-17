@extends('layouts.app')
@section('title', 'FFB Receive - Pratonton')

@section('content')
@php($presentation = \App\Support\FfbReceivePresentation::class)
<a href="{{ route('ffb-receive.index') }}" class="mb-4 inline-block text-sm text-green-800 underline">Kembali ke FFB Receive</a>
<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm">
    Membuka pratonton tidak mengesahkan penerimaan secara automatik. Rekod harian dan KPI tidak diubah.
    Status Sedia untuk Semakan Pegawai belum merupakan pengesahan pegawai.
</div>
@if(app(\App\Services\FfbDailyReceiptService::class)->isParallelRun($batch->receipt_date->toDateString()))
<div role="status" class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
    <strong>PARALLEL RUN · REKOD LATIHAN</strong>
    <p>Batch ini tidak dipautkan kepada Input Harian, tidak menggantikan BTS rasmi dan tidak digunakan untuk KPI operasi.</p>
</div>
@endif
@if($batch->confirmed_at)
<div class="mb-4 rounded-lg border border-green-300 bg-green-50 p-4 text-sm text-green-900">
    <h3 class="font-semibold">{{ $batch->status === 'superseded' ? 'Versi Lama / Digantikan' : 'Penerimaan BTS Disahkan / Dikunci' }}</h3>
    <p>Disahkan oleh: {{ $batch->confirmer?->name ?? 'Pengguna #'.$batch->confirmed_by }} pada {{ $batch->confirmed_at->timezone('Asia/Kuala_Lumpur')->format('d/m/Y H:i:s') }} (MYT).</p>
    <p>{{ data_get($batch->confirmation_snapshot, 'ticket_count', $batch->ticket_count) }} tiket / {{ $presentation::mt($batch->confirmed_daily_total_mt) }}. Pengesahan muktamad; tiada pindaan, pembatalan atau import semula.</p>
    @if($batch->status === 'superseded')
    <p class="mt-2 font-semibold">Digantikan oleh batch #{{ $supersededBy?->id ?? 'tidak diketahui' }}.</p>
    @endif
    @if($batch->status === 'confirmed')
    @can('correct', $batch)
    <a href="{{ route('ffb-receive.correction.create', $batch) }}" class="mt-3 inline-block rounded-lg border border-green-800 px-4 py-2 font-semibold text-green-800">Buat Pembetulan</a>
    @endcan
    @endif
    @if($batch->receipt_type === 'zero_receipt')<p>Pengakuan tiada penerimaan BTS. Tiada fail Excel atau tiket dicipta.</p>@endif
    @if($batch->daily_operation_id)
    <a class="mt-2 inline-block underline" href="{{ route('rekod-harian.show', $batch->daily_operation_id) }}">Lihat Input Harian #{{ $batch->daily_operation_id }}</a>
    @elseif(app(\App\Services\FfbDailyReceiptService::class)->applies($batch->receipt_date->toDateString()))
    @can('create', \App\Models\FfbImportBatch::class)
    <a class="mt-2 inline-block rounded ppnj-green p-2 text-white" href="{{ route('data-harian.create', ['mill_id' => $batch->mill_id, 'tarikh' => $batch->receipt_date->toDateString()]) }}">Input Harian</a>
    @endcan
    @elseif(!app(\App\Services\FfbDailyReceiptService::class)->isParallelRun($batch->receipt_date->toDateString()))
    <p class="mt-2">Tarikh sebelum 01/12/2026: rekod sejarah tidak dipautkan atau ditindih.</p>
    @endif
</div>
@elseif($batch->status === 'ready' && !$confirmed)
@can('confirm', $batch)
<a href="{{ route('ffb-receive.confirmation', $batch) }}" class="mb-4 inline-block rounded-lg ppnj-green px-4 py-2 font-semibold text-white">Sahkan Penerimaan BTS</a>
@endcan
@endif
@if($batch->status === 'failed')
<div role="alert" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-4 text-red-800">Import gagal. Fail asal dikekalkan. Tiada pratonton tiket lengkap tersedia.</div>
@elseif($batch->status === 'uploaded')
<div role="status" class="mb-4 rounded-lg border border-yellow-300 bg-yellow-50 p-4">Batch Dimuat Naik belum mempunyai pratonton lengkap. Hubungi Admin jika pemprosesan terhenti.</div>
@endif

@if($correctionHistory)
<section class="mb-6 rounded-xl bg-white p-5 shadow-sm">
    <h3 class="font-semibold">Sejarah Pembetulan</h3>
    <ol class="mt-3 space-y-3">
        @foreach($correctionHistory as $version)
        <li class="rounded border p-3 text-sm">
            <div class="flex flex-wrap items-center gap-2">
                <strong>Versi {{ $version['version'] }} · Batch #{{ $version['batch']->id }}</strong>
                <span>{{ \App\Support\FfbReceivePresentation::status($version['batch']->status) }}</span>
                @if($version['is_parallel_run'])
                <span class="font-semibold text-amber-800">Parallel Run · Latihan</span>
                @endif
            </div>
            <p class="mt-1">Pegawai: {{ $version['batch']->confirmer?->name ?? $version['batch']->uploader?->name ?? 'Pengguna tidak diketahui' }}</p>
            @if($version['batch']->confirmed_at)
            <p>Masa pengesahan: {{ $version['batch']->confirmed_at->timezone('Asia/Kuala_Lumpur')->format('d/m/Y H:i:s') }} (MYT)</p>
            @endif
            @if($version['reason'])
            <p>Sebab Pembetulan: {{ $version['reason'] }}</p>
            @endif
            @if($version['batch']->supersedes_batch_id)
            <p>Versi terdahulu: #{{ $version['batch']->supersedes_batch_id }}</p>
            @endif
        </li>
        @endforeach
    </ol>
</section>
@endif

@if($correctionComparison)
<section class="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-5 text-sm">
    <h3 class="font-semibold">Perbandingan Sebelum dan Selepas</h3>
    <p class="mt-2">Versi terdahulu: {{ $correctionComparison['before']['ticket_count'] }} tiket / {{ $presentation::mt($correctionComparison['before']['total_mt']) }} MT</p>
    <p>Versi ini: {{ $correctionComparison['after']['ticket_count'] }} tiket / {{ $presentation::mt($correctionComparison['after']['total_mt']) }} MT</p>
    <p>Ditambah {{ count($correctionComparison['tickets']['added']) }}, dibuang {{ count($correctionComparison['tickets']['removed']) }}, berubah {{ count($correctionComparison['tickets']['changed']) }} tiket.</p>
</section>
@endif

<div class="mb-6 rounded-xl bg-white p-5 shadow-sm">
    <h3 class="font-semibold ppnj-green-text">Batch #{{ $batch->id }} - {{ $batch->mill->code === 'BBJ' ? 'KBB' : $batch->mill->code }} - {{ $batch->mill->name }}</h3>
    <dl class="mt-3 grid gap-3 text-sm md:grid-cols-3">
        <div><dt class="text-gray-500">Tarikh Penerimaan</dt><dd>{{ $batch->receipt_date->format('d/m/Y') }} (Harian)</dd></div>
        <div><dt class="text-gray-500">Status Import</dt><dd class="font-semibold">{{ $presentation::status($batch->status) }}</dd></div>
        <div><dt class="text-gray-500">Jumlah Tiket</dt><dd class="text-lg font-semibold">{{ $batch->ticket_count ?? '-' }}</dd></div>
        <div><dt class="text-gray-500">Tiket Perlu Semakan</dt><dd class="{{ $batch->unresolved_count ? 'font-semibold text-amber-800' : '' }}">{{ $batch->unresolved_count ?? '-' }}</dd></div>
        <div><dt class="text-gray-500">Jumlah Berat</dt><dd class="text-lg font-semibold">{{ $presentation::mt($batch->parsed_total_mt) }} @if(!$batch->has_complete_weight_total)<strong class="text-sm text-red-700">(tidak lengkap)</strong>@endif</dd></div>
        <div><dt class="text-gray-500">Bukti Penerimaan</dt><dd class="break-all">{{ $batch->receipt_type === 'zero_receipt' ? 'Pengakuan Tiada Penerimaan BTS (tanpa Excel)' : $batch->original_filename.' / '.$batch->detected_format }}</dd></div>
        <div><dt class="text-gray-500">Pemuat Naik</dt><dd>{{ $batch->uploader->name }}</dd></div>
        <div><dt class="text-gray-500">Unit Pilihan Pengguna</dt><dd>{{ $batch->confirmed_weight_unit ?? 'Tidak ditentukan' }}</dd></div>
    </dl>
    <p class="mt-3 text-xs text-gray-500">Berat dipaparkan kepada 2 tempat perpuluhan sahaja. Ketepatan penuh data dan pengiraan asal dikekalkan.</p>
    <details class="mt-3 text-sm">
        <summary class="cursor-pointer text-green-800">Butiran Pembundaran</summary>
        <p class="mt-2">Jumlah dibundarkan harian: {{ $presentation::mt(data_get($batch->reconciliation_evidence, 'daily_rounded_total_mt')) }}.</p>
        <p>Perbezaan pembundaran: {{ $presentation::mt(data_get($batch->reconciliation_evidence, 'rounding_difference_mt')) }}.</p>
    </details>
</div>

<div class="mb-6 grid gap-4 md:grid-cols-3">
    @foreach($categories as $category)
    <div class="rounded-xl bg-white p-4 shadow-sm">
        <h4 class="text-sm font-semibold">{{ $category['label'] }}</h4>
        <p class="mt-2">{{ $category['count'] }} tiket / {{ $presentation::mt($category['total_mt']) }}</p>
    </div>
    @endforeach
</div>
@if($unclassified)
<p class="mb-4 text-sm font-semibold text-red-700">{{ $unclassified }} tiket belum diklasifikasikan. Tiket ini tetap disimpan dan termasuk dalam jumlah tiket.</p>
@endif

<div class="mb-6 rounded-xl border border-yellow-200 bg-yellow-50 p-5 text-sm">
    <h3 class="mb-2 font-semibold">Validasi &amp; Rekonsiliasi</h3>
    @foreach(data_get($batch->validation_summary, 'errors', []) as $error)
    <p class="mb-1 text-red-800">{{ $presentation::message($error) }}</p>
    @endforeach
    @foreach(data_get($batch->validation_summary, 'warnings', []) as $warning)
    <p class="mb-1">{{ $presentation::message($warning) }}</p>
    @endforeach
    <p class="mt-2">Kawalan pengguna: {{ $batch->control_ticket_count ?? 'Tiada bilangan' }} tiket / {{ $presentation::mt($batch->control_total_mt) }}.</p>
    <p>Perbezaan bilangan: {{ data_get($batch->reconciliation_evidence, 'ticket_count_difference') ?? '-' }};
        Perbezaan berat: {{ $presentation::mt(data_get($batch->reconciliation_evidence, 'total_mt_difference')) }}.</p>
</div>

<div class="mb-6 overflow-x-auto rounded-xl bg-white p-5 shadow-sm">
    <h3 class="mb-2 font-semibold">Perbandingan Semasa (baca sahaja)</h3>
    <p class="mb-3 text-xs text-gray-500">Perbandingan dibaca semula apabila halaman dibuka. Tiada data sedia ada ditindih. Jumlah tiket mungkin merangkumi pendua atau berat tidak lengkap.</p>
    @if($confirmed)
    <p class="text-sm">Batch disahkan <a class="text-green-800 underline" href="{{ route('ffb-receive.show', $confirmed) }}">#{{ $confirmed->id }}</a>:
        {{ $confirmed->ticket_count }} tiket / {{ $presentation::mt($confirmed->confirmed_daily_total_mt) }}.
        Perbezaan import: {{ $batch->ticket_count === null ? '-' : $batch->ticket_count - $confirmed->ticket_count }} tiket /
        {{ $presentation::mt($batch->parsed_total_mt === null ? null : (float) $batch->parsed_total_mt - (float) $confirmed->confirmed_daily_total_mt) }}.
        Penerimaan disahkan dikunci secara kekal. Penggantian tidak dibenarkan.
    </p>
    @else
    <p class="text-sm">Tiada batch disahkan lain untuk kilang dan tarikh ini.</p>
    @endif
    <table class="mt-3 w-full text-left text-sm">
        <thead><tr><th class="p-2">Rekod Harian</th><th class="p-2">Shift</th><th class="p-2">BTS Diterima</th><th class="p-2">Perbezaan Import - Rekod Harian</th></tr></thead>
        <tbody>
            @forelse($dailyOperations as $operation)
            <tr>
                <td class="p-2">#{{ $operation->id }}</td><td class="p-2">{{ $operation->shift }}</td>
                <td class="p-2">{{ $presentation::mt($operation->bts_diterima) }}</td>
                <td class="p-2">{{ $operation->shift !== 'Harian' || $batch->parsed_total_mt === null ? 'Di luar skop / tiada jumlah' : $presentation::mt((float) $batch->parsed_total_mt - (float) $operation->bts_diterima) }}</td>
            </tr>
            @empty
            <tr><td colspan="4" class="p-2">Tiada rekod harian. Tiada rekod baharu dicipta oleh import.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($dailyOperations->contains(fn ($operation) => $operation->shift !== 'Harian'))
    <p class="mt-2 text-sm text-red-700">Rekod shift dikesan; pembahagian penerimaan belum disokong. Skop awal Harian sahaja.</p>
    @endif
</div>

<div class="overflow-x-auto rounded-xl bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-gray-50"><tr>
            <th scope="col" class="p-3">Lembaran / Baris</th><th scope="col" class="p-3">Tarikh / No. Tiket</th><th scope="col" class="p-3">No. D/O / Kenderaan</th>
            <th scope="col" class="p-3">Nama / Kod Sumber Asal</th><th scope="col" class="p-3">Sumber Dipadankan</th>
            <th scope="col" class="p-3">Kategori BTS</th><th scope="col" class="p-3">Berat Bersih</th><th scope="col" class="p-3">Butiran Tiket</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($tickets as $ticket)
            <tr class="{{ $ticket->requires_review ? 'bg-yellow-50' : '' }}">
                <td class="p-3">{{ $ticket->worksheet }}:{{ $ticket->row_number }}<br><span class="text-xs text-gray-500">Baris tajuk: {{ $ticket->heading_row_number ?? '-' }}</span></td>
                <td class="p-3">{{ $ticket->ticket_date?->format('d/m/Y') ?? '-' }}<br>{{ $ticket->ticket_number ?? '-' }}</td>
                <td class="p-3">{{ $ticket->do_number ?? '-' }}<br>{{ $ticket->vehicle_number ?? '-' }}</td>
                <td class="p-3 whitespace-pre-wrap">{{ $ticket->original_heading }}<br>{{ $ticket->original_source_code }}</td>
                <td class="p-3">{{ data_get($ticket->resolution_snapshot, 'source.code', '-') }} - {{ data_get($ticket->resolution_snapshot, 'source.name', '-') }}
                    @if($ticket->source_category === 'ppnj_ppk_integrated')
                    <p class="mt-1 text-xs text-gray-600">{{ data_get($ticket->resolution_snapshot, 'ppk.entity_type') === \App\Models\Ppk::INDEPENDENT_INTEGRATED_ESTATE ? 'Entiti Bersepadu Bebas' : 'PPK' }}: {{ data_get($ticket->resolution_snapshot, 'ppk.name') ?? 'Belum dipadankan' }}</p>
                    @endif
                </td>
                <td class="p-3">{{ $presentation::category($ticket->source_category) }}</td>
                <td class="p-3 whitespace-nowrap">{{ $ticket->weight_mt === null ? 'Tidak sah / tidak diketahui' : $presentation::mt($ticket->weight_mt) }}</td>
                <td class="p-3">
                    <p class="{{ $ticket->requires_review ? 'font-medium text-amber-800' : 'text-gray-600' }}">{{ $ticket->requires_review ? 'Tiket Perlu Semakan' : 'Tiada isu tiket dikesan' }}</p>
                    <details class="mt-2"><summary class="cursor-pointer text-green-800">Nilai asal, snapshot &amp; isu teknikal</summary>
                        @foreach($ticket->review_reasons ?? [] as $reason)<p class="mt-2 text-xs text-red-800">{{ $reason }}</p>@endforeach
                        <p class="mt-2 text-xs">Unit asal: {{ $ticket->weight_unit ?? '-' }}; sumber unit: {{ $ticket->weight_unit_origin ?? '-' }}.</p>
                        <pre class="mt-2 max-w-md whitespace-pre-wrap break-all text-xs">{{ json_encode($presentation::ticketEvidence($ticket->raw_values, $ticket->resolution_snapshot, $ticket->source_category), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </details>
                </td>
            </tr>
            @empty
            <tr><td colspan="8" class="p-6 text-center">Tiada tiket tersedia.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $tickets->links() }}</div>
@endsection
