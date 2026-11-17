@extends('layouts.app')
@section('title', 'FFB Receive')

@section('content')
@php($presentation = \App\Support\FfbReceivePresentation::class)
<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
    Muat naik menghasilkan pratonton sahaja. Pengesahan penerimaan dibuat secara berasingan oleh pengguna berkuasa.
    Pengesahan adalah muktamad dan dikunci. Mulai 01/12/2026, Input Harian memerlukan penerimaan BTS yang telah disahkan (Harian).
    <p class="mt-2 font-semibold">Parallel Run: 05/11/2026–30/11/2026. Rekod latihan tidak dipautkan kepada Input Harian dan tidak mengubah KPI operasi.</p>
</div>

@can('create', \App\Models\FfbImportBatch::class)
<form method="GET" action="{{ route('ffb-receive.zero-confirmation') }}" class="mb-6 rounded-xl bg-white p-6 shadow-sm">
    <h3 class="mb-3 font-semibold">Tiada Penerimaan BTS</h3>
    <p class="mb-3 text-sm">Gunakan hanya apabila tiada penerimaan buah. Semak ringkasan sebelum mengesahkan 0.00 MT.</p>
    <div class="grid gap-3 md:grid-cols-2">
        <label>Kilang
            <select name="mill_id" required class="w-full rounded border p-2">
                @foreach($mills->where('is_active', true) as $mill)
                <option value="{{ $mill->id }}" @selected((string) request('mill_id', auth()->user()->mill_id) === (string) $mill->id)>{{ $mill->code === 'BBJ' ? 'KBB' : $mill->code }} - {{ $mill->name }}</option>
                @endforeach
            </select>
        </label>
        <label>Tarikh Penerimaan
            <input type="date" name="receipt_date" required value="{{ request('receipt_date', now('Asia/Kuala_Lumpur')->toDateString()) }}" max="{{ now('Asia/Kuala_Lumpur')->toDateString() }}" class="w-full rounded border p-2">
        </label>
    </div>
    <button class="mt-4 rounded-lg border border-green-800 px-4 py-2 text-green-800">Semak Tiada Penerimaan BTS</button>
</form>
<form method="POST" action="{{ route('ffb-receive.store') }}" enctype="multipart/form-data" class="mb-6 rounded-xl bg-white p-6 shadow-sm">
    @csrf
    <h3 class="mb-4 font-semibold ppnj-green-text">Upload Excel - Timbang</h3>
    <div class="grid gap-4 md:grid-cols-2">
        <div>
            <label for="mill_id" class="mb-1 block text-sm font-medium">Kilang</label>
            <select id="mill_id" name="mill_id" required class="w-full rounded-lg border px-3 py-2">
                @foreach($mills->where('is_active', true) as $mill)
                <option value="{{ $mill->id }}" @selected((string) old('mill_id', request('mill_id', auth()->user()->mill_id)) === (string) $mill->id)>{{ $mill->code === 'BBJ' ? 'KBB' : $mill->code }} - {{ $mill->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="receipt_date" class="mb-1 block text-sm font-medium">Tarikh Penerimaan</label>
            <input id="receipt_date" name="receipt_date" type="date" required min="1000-01-01" max="{{ now('Asia/Kuala_Lumpur')->toDateString() }}" value="{{ old('receipt_date', request('receipt_date', now('Asia/Kuala_Lumpur')->toDateString())) }}" class="w-full rounded-lg border px-3 py-2">
        </div>
        <div>
            <label for="workbook" class="mb-1 block text-sm font-medium">Fail Excel (XLS / XLSX, maksimum 10 MB)</label>
            <input id="workbook" name="workbook" type="file" accept=".xls,.xlsx" required class="w-full rounded-lg border px-3 py-2">
            <p class="mt-1 text-xs text-gray-500">Format disahkan daripada kandungan, bukan nama fail. Disimpan secara peribadi.</p>
        </div>
        <div>
            <label for="confirmed_weight_unit" class="mb-1 block text-sm font-medium">Unit Berat Jika Tiada Label Dalam Fail</label>
            <select id="confirmed_weight_unit" name="confirmed_weight_unit" class="w-full rounded-lg border px-3 py-2">
                <option value="">Belum ditentukan (perlu semakan jika tiada label)</option>
                <option value="KG" @selected(old('confirmed_weight_unit') === 'KG')>KG</option>
                <option value="MT" @selected(old('confirmed_weight_unit') === 'MT')>MT</option>
            </select>
            <p class="mt-1 text-xs text-gray-500">Pilihan ini mengesahkan unit sahaja, bukan pengesahan batch. Label unit dalam fail diberi keutamaan.</p>
        </div>
        <div>
            <label for="control_ticket_count" class="mb-1 block text-sm font-medium">Bilangan Tiket Kawalan (pilihan)</label>
            <input id="control_ticket_count" name="control_ticket_count" type="number" min="0" max="4294967295" step="1" value="{{ old('control_ticket_count') }}" class="w-full rounded-lg border px-3 py-2">
        </div>
        <div>
            <label for="control_total_mt" class="mb-1 block text-sm font-medium">Jumlah Kawalan MT (pilihan)</label>
            <input id="control_total_mt" name="control_total_mt" type="number" min="0" max="999999999.999999" step="0.000001" value="{{ old('control_total_mt') }}" class="w-full rounded-lg border px-3 py-2">
        </div>
    </div>
    <p class="mt-3 text-xs text-gray-500">Kawalan mesti datang daripada semakan bebas; subtotal buku kerja tidak direkonsiliasi secara automatik.</p>
    <button class="mt-4 rounded-lg ppnj-green px-4 py-2 font-semibold text-white">Upload Excel &amp; Semak Penerimaan</button>
</form>
@else
<p class="mb-4 text-sm text-gray-600">Akses baca sahaja mengikut skop kilang anda.</p>
@endcan

<div class="overflow-x-auto rounded-xl bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-gray-50 text-gray-600"><tr>
            <th class="p-3">Batch Import</th><th class="p-3">Kilang</th><th class="p-3">Tarikh Penerimaan</th>
            <th class="p-3">Nama Fail</th><th class="p-3">Status Import</th><th class="p-3">Jumlah Tiket / Berat</th><th class="p-3">Pemuat Naik</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse($batches as $batch)
            <tr>
                <td class="p-3"><a class="font-semibold text-green-800 underline" href="{{ route('ffb-receive.show', $batch) }}">#{{ $batch->id }}</a></td>
                <td class="p-3">{{ $batch->mill->code === 'BBJ' ? 'KBB' : $batch->mill->code }}</td>
                <td class="p-3">{{ $batch->receipt_date->format('d/m/Y') }}
                    @if(app(\App\Services\FfbDailyReceiptService::class)->isParallelRun($batch->receipt_date->toDateString()))
                    <p class="mt-1 text-xs font-semibold text-amber-800">Parallel Run · Latihan</p>
                    @endif
                </td>
                <td class="p-3 break-all">{{ $batch->receipt_type === 'zero_receipt' ? 'Tiada Penerimaan BTS (tanpa Excel)' : $batch->original_filename }}</td>
                <td class="p-3">{{ $presentation::status($batch->status) }}
                    @if($batch->confirmed_at)
                    <p class="mt-1 text-xs text-gray-600">{{ $batch->confirmer?->name ?? 'Pengguna #'.$batch->confirmed_by }}<br>{{ $batch->confirmed_at->timezone('Asia/Kuala_Lumpur')->format('d/m/Y H:i:s') }} (MYT)</p>
                    @endif
                </td>
                <td class="p-3">
                    {{ $batch->ticket_count ?? '-' }} tiket / {{ $presentation::mt($batch->parsed_total_mt) }}
                    @if($batch->unresolved_count)
                    <p class="mt-1 text-xs text-amber-800">Tiket Perlu Semakan: {{ $batch->unresolved_count }}</p>
                    @endif
                    @if($batch->parsed_total_mt !== null && !$batch->has_complete_weight_total)
                    <p class="mt-1 text-xs text-red-700">Jumlah berat tidak lengkap</p>
                    @endif
                </td>
                <td class="p-3">{{ $batch->uploader->name }}</td>
            </tr>
            @empty
            <tr><td colspan="7" class="p-6 text-center text-gray-500">Tiada batch import dalam skop ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $batches->links() }}</div>
@endsection
