@extends('layouts.app')
@section('title', 'Kemaskini Kualiti')

@section('content')
<div class="mb-4 p-3 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 text-sm">
    Senarai rekod yang belum diisi data kualiti (OER, KER, FFA, Moisture, Dirt, Throughput, Utilisation). Isi selepas keputusan lab diterima.
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
                <th class="px-4 py-3 text-left">Tarikh</th>
                <th class="px-4 py-3 text-left">Kilang</th>
                <th class="px-4 py-3 text-left">Shift</th>
                <th class="px-4 py-3 text-right">BTS Diproses</th>
                <th class="px-4 py-3 text-right">CPO</th>
                <th class="px-4 py-3 text-center">Tindakan</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse($records as $r)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">{{ $r->tarikh->format('d/m/Y') }}</td>
                <td class="px-4 py-3">{{ $r->mill->name }}</td>
                <td class="px-4 py-3">{{ $r->shift }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($r->bts_diproses,2) }}</td>
                <td class="px-4 py-3 text-right">{{ number_format($r->pengeluaran_cpo,2) }}</td>
                <td class="px-4 py-3 text-center">
                    <a href="{{ route('data-harian.edit-quality', $r) }}" class="px-3 py-1.5 rounded-lg ppnj-green text-white text-xs">Isi Data Kualiti</a>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Tiada rekod tertunggak. Semua data kualiti dah dikemaskini. 🎉</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $records->links() }}</div>
@endsection
