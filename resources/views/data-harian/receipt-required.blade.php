@extends('layouts.app')
@section('title', 'Input Harian - Pengesahan BTS')
@section('content')
<div class="max-w-3xl rounded-xl bg-white p-6 shadow-sm">
    <h2 class="mb-4 text-lg font-semibold">Input Harian</h2>
    <p>Mulai {{ app(\App\Services\FfbDailyReceiptService::class)->effectiveDate() }}, penerimaan BTS mesti disahkan sebelum membuka borang operasi.</p>
    <form method="GET" action="{{ route('data-harian.create') }}" class="my-4 grid gap-3">
        <label>Kilang
            <select name="mill_id" required class="w-full rounded border p-2">
                @foreach($mills as $mill)
                <option value="{{ $mill->id }}" @selected((int)$selectedMillId === $mill->id)>{{ $mill->code === 'BBJ' ? 'KBB' : $mill->code }} - {{ $mill->name }}</option>
                @endforeach
            </select>
        </label>
        <label>Tarikh <input type="date" name="tarikh" value="{{ $selectedTarikh }}" max="{{ now('Asia/Kuala_Lumpur')->toDateString() }}" required class="w-full rounded border p-2"></label>
        <button class="rounded ppnj-green p-2 text-white">Semak Pengesahan BTS</button>
    </form>
    @if($existing)
    <p role="alert" class="mb-3 text-red-800">Rekod harian sudah wujud. Rekod tidak akan ditindih.</p>
    <a href="{{ route('rekod-harian.index') }}" class="text-green-800 underline">Lihat Rekod Harian</a>
    @else
    <p role="alert" class="mb-3 text-amber-800">Sahkan penerimaan Excel atau tiada penerimaan BTS terlebih dahulu.</p>
    <a href="{{ route('ffb-receive.index', ['mill_id' => $selectedMillId, 'receipt_date' => $selectedTarikh]) }}" class="text-green-800 underline">Buka FFB Receive</a>
    @endif
</div>
@endsection
