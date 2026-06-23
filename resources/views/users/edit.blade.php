@extends('layouts.app')
@section('title', 'Kemaskini Pengguna')

@section('content')
<div class="bg-white rounded-xl shadow-sm p-6 max-w-xl">
    <form method="POST" action="{{ route('users.update', $user) }}" class="space-y-4">
        @csrf @method('PUT')
        <div>
            <label class="block text-xs text-gray-500 mb-1">Nama Penuh *</label>
            <input type="text" name="name" value="{{ old('name', $user->name) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Email *</label>
            <input type="email" name="email" value="{{ old('email', $user->email) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Kata Laluan Baharu (kosongkan jika tidak ditukar)</label>
            <input type="password" name="password" class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Peranan *</label>
            <select name="role_id" id="role_id" required class="w-full border rounded-lg px-3 py-2 text-sm" onchange="toggleMill()">
                @foreach($roles as $role)
                    <option value="{{ $role->id }}" data-name="{{ $role->name }}" {{ $user->role_id == $role->id ? 'selected' : '' }}>{{ $role->label }}</option>
                @endforeach
            </select>
        </div>
        <div id="millWrapper">
            <label class="block text-xs text-gray-500 mb-1">Kilang (untuk Pegawai Kilang)</label>
            <select name="mill_id" class="w-full border rounded-lg px-3 py-2 text-sm">
                <option value="">- Tiada -</option>
                @foreach($mills as $mill)
                    <option value="{{ $mill->id }}" {{ $user->mill_id == $mill->id ? 'selected' : '' }}>{{ $mill->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Status</label>
            <select name="is_active" class="w-full border rounded-lg px-3 py-2 text-sm">
                <option value="1" {{ $user->is_active ? 'selected' : '' }}>Aktif</option>
                <option value="0" {{ !$user->is_active ? 'selected' : '' }}>Tidak Aktif</option>
            </select>
        </div>
        <div class="flex justify-end gap-3 pt-2 border-t">
            <a href="{{ route('users.index') }}" class="px-4 py-2 rounded-lg border text-sm">Batal</a>
            <button class="px-5 py-2 rounded-lg ppnj-green text-white text-sm">Kemaskini</button>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
function toggleMill() {
    const sel = document.getElementById('role_id');
    const isPegawai = sel.options[sel.selectedIndex].dataset.name === 'pegawai_kilang';
    document.getElementById('millWrapper').style.display = isPegawai ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', toggleMill);
</script>
@endsection
