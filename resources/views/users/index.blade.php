@extends('layouts.app')
@section('title', 'Pengurusan Pengguna')

@section('content')
<div class="flex justify-end mb-4">
    <a href="{{ route('users.create') }}" class="px-4 py-2 rounded-lg ppnj-green text-white text-sm">+ Tambah Pengguna</a>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
                <th class="px-4 py-3 text-left">Nama</th>
                <th class="px-4 py-3 text-left">Email</th>
                <th class="px-4 py-3 text-left">Peranan</th>
                <th class="px-4 py-3 text-left">Kilang</th>
                <th class="px-4 py-3 text-center">Status</th>
                <th class="px-4 py-3 text-center">Tindakan</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @foreach($users as $u)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">{{ $u->name }}</td>
                <td class="px-4 py-3">{{ $u->email }}</td>
                <td class="px-4 py-3">{{ $u->role->label ?? '-' }}</td>
                <td class="px-4 py-3">{{ $u->mill->name ?? '-' }}</td>
                <td class="px-4 py-3 text-center">
                    <span class="px-2 py-1 rounded-full text-xs {{ $u->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $u->is_active ? 'Aktif' : 'Tidak Aktif' }}
                    </span>
                </td>
                <td class="px-4 py-3 text-center space-x-2 whitespace-nowrap">
                    <a href="{{ route('users.edit', $u) }}" class="text-amber-600 hover:underline">Edit</a>
                    @if($u->id !== auth()->id())
                    <form method="POST" action="{{ route('users.destroy', $u) }}" class="inline" onsubmit="return confirm('Padam pengguna ini?')">
                        @csrf @method('DELETE')
                        <button class="text-red-600 hover:underline">Padam</button>
                    </form>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
