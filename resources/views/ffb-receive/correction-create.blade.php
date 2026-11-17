@extends('layouts.app')
@section('title', 'Buat Pembetulan FFB')

@section('content')
<a href="{{ route('ffb-receive.show', $batch) }}" class="mb-4 inline-block text-sm text-green-800 underline">Kembali ke Batch #{{ $batch->id }}</a>
<div class="mx-auto max-w-3xl rounded-xl bg-white p-6 shadow-sm">
    <h2 class="text-xl font-semibold ppnj-green-text">Buat Pembetulan</h2>
    <p class="mt-2 text-sm text-gray-600">
        Versi asal kekal sebagai bukti audit. Pembetulan ini untuk
        {{ $batch->mill->code === 'BBJ' ? 'KBB' : $batch->mill->code }}
        pada {{ $batch->receipt_date->format('d/m/Y') }}.
    </p>
    @if(app(\App\Services\FfbDailyReceiptService::class)->isParallelRun($batch->receipt_date->toDateString()))
    <p role="status" class="mt-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm font-semibold text-amber-950">
        PARALLEL RUN · PEMBETULAN LATIHAN. Daily Operations dan KPI rasmi tidak akan diubah.
    </p>
    @endif
    <form method="POST" action="{{ route('ffb-receive.correction.store', $batch) }}" enctype="multipart/form-data" class="mt-5 space-y-4">
        @csrf
        <label class="block text-sm font-medium">
            Sebab Pembetulan
            <textarea name="correction_reason" required minlength="5" maxlength="500" rows="3" class="mt-1 w-full rounded-lg border px-3 py-2">{{ old('correction_reason') }}</textarea>
        </label>
        <label class="block text-sm font-medium">
            Jenis Penggantian
            <select name="receipt_type" required class="mt-1 w-full rounded-lg border px-3 py-2">
                <option value="excel" @selected(old('receipt_type', 'excel') === 'excel')>Upload Excel Pembetulan</option>
                <option value="zero_receipt" @selected(old('receipt_type') === 'zero_receipt')>Pengesahan Sifar</option>
            </select>
        </label>
        <label class="block text-sm font-medium">
            Upload Excel Pembetulan (XLS / XLSX, maksimum 10 MB)
            <input type="file" name="workbook" accept=".xls,.xlsx" class="mt-1 block w-full rounded-lg border px-3 py-2">
        </label>
        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-medium">
                Unit Berat Jika Tiada Label
                <select name="confirmed_weight_unit" class="mt-1 w-full rounded-lg border px-3 py-2">
                    <option value="">Belum ditentukan</option>
                    <option value="KG" @selected(old('confirmed_weight_unit') === 'KG')>KG</option>
                    <option value="MT" @selected(old('confirmed_weight_unit') === 'MT')>MT</option>
                </select>
            </label>
            <label class="block text-sm font-medium">
                Bilangan Tiket Kawalan
                <input type="number" name="control_ticket_count" min="0" max="4294967295" value="{{ old('control_ticket_count') }}" class="mt-1 w-full rounded-lg border px-3 py-2">
            </label>
        </div>
        <label class="block text-sm font-medium">
            Jumlah Kawalan MT
            <input type="number" name="control_total_mt" min="0" max="999999999.999999" step="0.000001" value="{{ old('control_total_mt') }}" class="mt-1 w-full rounded-lg border px-3 py-2">
        </label>
        <p class="text-xs text-gray-500">Calon pembetulan perlu disemak dan diakui sebelum ia menjadi versi aktif.</p>
        <button class="rounded-lg ppnj-green px-4 py-2 font-semibold text-white">Sediakan Perbandingan Pembetulan</button>
    </form>
</div>
@endsection
