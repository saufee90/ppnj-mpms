@extends('layouts.app')
@section('title', 'Sahkan Tiada Penerimaan BTS')
@section('content')
<div class="max-w-3xl rounded-xl bg-white p-6 shadow-sm">
    <h2 class="mb-4 text-lg font-semibold">Sahkan Tiada Penerimaan BTS</h2>
    <p>Kilang: {{ $mill->code === 'BBJ' ? 'KBB' : $mill->code }} - {{ $mill->name }}</p>
    <p>Tarikh penerimaan: {{ $input['receipt_date'] }} (Harian)</p>
    @if(app(\App\Services\FfbDailyReceiptService::class)->isParallelRun($input['receipt_date']))
    <p role="status" class="my-3 rounded border border-amber-300 bg-amber-50 p-3 font-semibold text-amber-950">
        PARALLEL RUN · REKOD LATIHAN. Pengesahan sifar tidak dipautkan kepada Input Harian dan tidak mengubah KPI operasi.
    </p>
    @endif
    <p class="my-4 text-xl font-bold">0 tiket / 0.00 MT</p>
    <p>Tiada fail Excel atau tiket akan dicipta. Pengesahan ini muktamad, termasuk untuk Admin. Tiada pindaan, pembatalan, import semula atau pengesahan semula dibenarkan.</p>
    @foreach($summary['errors'] as $error)
    <p class="mt-3 text-red-800" role="alert">{{ $error }}</p>
    @endforeach
    @if(!$summary['errors'])
    <form method="POST" action="{{ route('ffb-receive.zero-confirm') }}" class="mt-5">
        @csrf
        <input type="hidden" name="mill_id" value="{{ $mill->id }}">
        <input type="hidden" name="receipt_date" value="{{ $input['receipt_date'] }}">
        <input type="hidden" name="confirmation_token" value="{{ $summary['token'] }}">
        <label class="flex items-start gap-3">
            <input type="checkbox" name="acknowledgement" value="1" required class="mt-1">
            <span>Saya mengesahkan tiada penerimaan BTS bagi kilang dan tarikh ini. Saya faham rekod ini akan dikunci secara kekal.</span>
        </label>
        <button class="mt-4 rounded-lg ppnj-green px-4 py-2 font-semibold text-white">Sahkan Tiada Penerimaan BTS</button>
    </form>
    @endif
    <a class="mt-4 inline-block text-green-800 underline" href="{{ route('ffb-receive.index') }}">Kembali ke FFB Receive</a>
</div>
@endsection
