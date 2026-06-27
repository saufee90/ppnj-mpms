@extends('layouts.app')
@section('title', 'Tambah Pengguna')

@section('content')
<div class="bg-white rounded-xl shadow-sm p-6 max-w-xl">
    <form method="POST" action="{{ route('users.store') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-xs text-gray-500 mb-1">Nama Penuh *</label>
            <input type="text" name="name" value="{{ old('name') }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Email *</label>
            <input type="email" name="email" value="{{ old('email') }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Kata Laluan *</label>
            <input type="password" name="password" required class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Peranan *</label>
            <select name="role_id" id="role_id" required class="w-full border rounded-lg px-3 py-2 text-sm" onchange="toggleMill()">
                @foreach($roles as $role)
                    <option value="{{ $role->id }}" data-name="{{ $role->name }}">{{ $role->label }}</option>
                @endforeach
            </select>
        </div>
        <div id="millWrapper">
            <label class="block text-xs text-gray-500 mb-1">Kilang (untuk Pegawai Kilang / Pengurus Kilang)</label>
            <select id="mill_id" name="mill_id" class="w-full border rounded-lg px-3 py-2 text-sm">
                <option value="">- Tiada -</option>
                @foreach($mills as $mill)
                    <option value="{{ $mill->id }}" {{ (string)old('mill_id') === (string)$mill->id ? 'selected' : '' }}>{{ $mill->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex justify-end gap-3 pt-2 border-t">
            <a href="{{ route('users.index') }}" class="px-4 py-2 rounded-lg border text-sm">Batal</a>
            <button class="px-5 py-2 rounded-lg ppnj-green text-white text-sm">Simpan</button>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
function toggleMill() {
    const sel = document.getElementById('role_id');
    const roleName = sel.options[sel.selectedIndex].dataset.name;
    const needsMill = roleName === 'pegawai_kilang' || roleName === 'pengurus_kilang';
    const wrapper = document.getElementById('millWrapper');
    const millSelect = document.getElementById('mill_id');

    wrapper.style.display = needsMill ? 'block' : 'none';
    millSelect.required = needsMill;
    if (!needsMill) {
        millSelect.value = '';
    }
}
document.addEventListener('DOMContentLoaded', toggleMill);
</script>
@endsection
