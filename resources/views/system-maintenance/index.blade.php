@extends('layouts.app')
@section('title', 'System Maintenance Manager')

@section('content')
<div class="space-y-6">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">
            <div>
                <p class="text-xs uppercase tracking-[0.22em] text-gray-400 font-semibold">Pentadbiran</p>
                <h1 class="mt-2 text-2xl md:text-3xl font-black ppnj-green-text">System Maintenance Manager</h1>
                <p class="mt-2 text-sm text-gray-600 max-w-3xl">Kawal mod penyelenggaraan sistem, papar mesej kepada pengguna, dan simpan sejarah perubahan dengan selamat untuk production.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 min-w-[280px]">
                <div class="rounded-2xl p-4 border {{ ($settings['maintenance_enabled'] ?? false) ? 'border-red-200 bg-red-50' : 'border-green-200 bg-green-50' }}">
                    <div class="text-xs uppercase tracking-[0.18em] text-gray-500 font-semibold">Status Semasa</div>
                    <div class="mt-2 text-lg font-black {{ ($settings['maintenance_enabled'] ?? false) ? 'text-red-700' : 'text-green-700' }}">
                        {{ ($settings['maintenance_enabled'] ?? false) ? 'MAINTENANCE MODE AKTIF' : 'ONLINE' }}
                    </div>
                </div>
                <div class="rounded-2xl p-4 border border-amber-200 bg-amber-50">
                    <div class="text-xs uppercase tracking-[0.18em] text-gray-500 font-semibold">Versi Sistem</div>
                    <div class="mt-2 text-lg font-black text-amber-800">MPS v{{ $settings['system_version'] ?? '1.0.5' }}</div>
                </div>
            </div>
        </div>
    </div>

    <form id="maintenance-form" method="POST" action="{{ route('maintenance.update') }}" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8 space-y-6">
        @csrf
        @method('PUT')
        <input type="hidden" name="maintenance_enabled" value="0">

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div>
                <label class="flex items-center justify-between gap-4 rounded-2xl border border-gray-200 p-4 cursor-pointer hover:border-green-300">
                    <span>
                        <span class="block text-sm font-semibold text-gray-700">Maintenance Mode</span>
                        <span class="block text-xs text-gray-500 mt-1">Aktifkan untuk menyekat semua pengguna selain Admin.</span>
                    </span>
                    <span class="relative inline-flex items-center">
                        <input id="maintenance-enabled" type="checkbox" name="maintenance_enabled" value="1" class="peer sr-only" {{ old('maintenance_enabled', (bool) ($settings['maintenance_enabled'] ?? false)) ? 'checked' : '' }}>
                        <span class="h-7 w-12 rounded-full bg-gray-300 transition peer-checked:bg-red-600"></span>
                        <span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                    </span>
                </label>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Jenis Penyelenggaraan *</label>
                    <select name="maintenance_type" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                        @foreach($maintenanceOptions as $value => $label)
                            <option value="{{ $value }}" {{ old('maintenance_type', $settings['maintenance_type'] ?? 'system_update') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Versi Sistem *</label>
                    <input type="text" name="system_version" value="{{ old('system_version', $settings['system_version'] ?? '1.0.5') }}" maxlength="30" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Tajuk *</label>
                <input type="text" name="maintenance_title" value="{{ old('maintenance_title', $settings['maintenance_title'] ?? '') }}" maxlength="150" class="w-full border rounded-xl px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Mesej *</label>
                <textarea name="maintenance_message" rows="5" maxlength="2000" class="w-full border rounded-xl px-3 py-2.5 text-sm">{{ old('maintenance_message', $settings['maintenance_message'] ?? '') }}</textarea>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Maintenance Notes</label>
                <textarea name="maintenance_notes" rows="5" maxlength="3000" placeholder="Setiap baris akan dipaparkan sebagai item senarai." class="w-full border rounded-xl px-3 py-2.5 text-sm">{{ old('maintenance_notes', $settings['maintenance_notes'] ?? '') }}</textarea>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Anggaran Tamat</label>
                <input type="datetime-local" name="maintenance_end_at" value="{{ old('maintenance_end_at', isset($settings['maintenance_end_at']) && $settings['maintenance_end_at'] ? \Carbon\Carbon::parse($settings['maintenance_end_at'])->format('Y-m-d\TH:i') : '') }}" class="w-full border rounded-xl px-3 py-2.5 text-sm">
            </div>
            <div class="flex items-end gap-3 flex-wrap">
                <button type="button" id="preview-button" class="px-4 py-2.5 rounded-xl border border-gray-300 text-sm font-semibold hover:bg-gray-50">Preview Paparan</button>
                <button type="button" id="save-button" class="px-4 py-2.5 rounded-xl ppnj-green text-white text-sm font-semibold">Simpan Tetapan</button>
                @if(($settings['maintenance_enabled'] ?? false))
                    <button type="button" id="disable-button" class="px-4 py-2.5 rounded-xl bg-red-600 text-white text-sm font-semibold hover:bg-red-700">Matikan Maintenance</button>
                @endif
            </div>
        </div>
    </form>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
            <div class="flex items-center justify-between gap-3 mb-5">
                <h2 class="text-lg font-bold ppnj-green-text">Maintenance History</h2>
                <span class="text-xs text-gray-500">10 rekod terkini</span>
            </div>
            <div class="space-y-3">
                @forelse($history as $log)
                    @php
                        $summary = data_get($log->new_values, 'summary', []);
                        $changes = data_get($summary, 'changes', []);
                    @endphp
                    <div class="rounded-xl border border-gray-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-gray-800">{{ $log->user->name ?? 'Sistem' }}</div>
                                <div class="text-sm text-gray-600 mt-1">{{ data_get($summary, 'description', $log->action) }}</div>
                            </div>
                            <div class="text-xs text-gray-400 text-right">{{ $log->created_at->format('d/m/Y H:i') }}</div>
                        </div>
                        @if(!empty($changes))
                            <div class="mt-3 space-y-2">
                                @foreach($changes as $change)
                                    <div class="rounded-lg bg-gray-50 border border-gray-200 px-3 py-2 text-xs text-gray-600 leading-5">
                                        <div class="font-semibold text-gray-700">{{ $change['label'] ?? $change['field'] }}</div>
                                        <div class="mt-1">Dari: <span class="text-gray-500">{{ $change['old'] ?? 'Tiada' }}</span></div>
                                        <div>Kepada: <span class="text-gray-900">{{ $change['new'] ?? 'Tiada' }}</span></div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 p-6 text-sm text-gray-500">Tiada sejarah maintenance direkodkan lagi.</div>
                @endforelse
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
            <h2 class="text-lg font-bold ppnj-green-text mb-4">Panduan Ringkas</h2>
            <ul class="space-y-3 text-sm text-gray-600 leading-6">
                <li class="flex gap-3"><span class="mt-2 h-2.5 w-2.5 rounded-full bg-green-500"></span><span>Aktifkan maintenance hanya apabila semua pengguna bukan Admin perlu disekat.</span></li>
                <li class="flex gap-3"><span class="mt-2 h-2.5 w-2.5 rounded-full bg-amber-500"></span><span>Gunakan Preview Paparan untuk semak teks, anggaran tamat dan nota sebelum aktivasi.</span></li>
                <li class="flex gap-3"><span class="mt-2 h-2.5 w-2.5 rounded-full bg-red-500"></span><span>Matikan maintenance selepas kerja selesai supaya pengguna biasa boleh kembali menggunakan sistem.</span></li>
            </ul>
        </div>
    </div>
</div>

<div id="maintenance-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-black/55" data-close-modal></div>
    <div class="relative z-10 min-h-full flex items-center justify-center p-4">
        <div class="w-full max-w-xl rounded-3xl bg-white shadow-2xl border border-gray-100 overflow-hidden">
            <div class="p-6 border-b bg-green-50">
                <h3 class="text-xl font-black ppnj-green-text" data-modal-title>Pengesahan Maintenance</h3>
                <p class="mt-2 text-sm text-gray-600" data-modal-message>Semak tindakan anda sebelum diteruskan.</p>
            </div>
            <div class="p-6 space-y-4">
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    Semua pengguna selain Admin akan disekat daripada mengakses sistem apabila Maintenance Mode diaktifkan.
                </div>
                <label class="flex items-start gap-3 text-sm text-gray-700">
                    <input id="confirm-checkbox" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-green-700 focus:ring-green-600">
                    <span>Saya memahami tindakan ini dan bersedia meneruskannya.</span>
                </label>
            </div>
            <div class="p-6 border-t flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                <button type="button" id="cancel-modal" class="px-4 py-2.5 rounded-xl border border-gray-300 text-sm font-semibold">Batal</button>
                <button type="button" id="confirm-modal" disabled class="px-4 py-2.5 rounded-xl ppnj-green text-white text-sm font-semibold disabled:opacity-60 disabled:cursor-not-allowed">Sahkan</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const form = document.getElementById('maintenance-form');
        const modal = document.getElementById('maintenance-modal');
        const saveButton = document.getElementById('save-button');
        const disableButton = document.getElementById('disable-button');
        const previewButton = document.getElementById('preview-button');
        const confirmButton = document.getElementById('confirm-modal');
        const cancelButton = document.getElementById('cancel-modal');
        const closeBackdrop = modal?.querySelector('[data-close-modal]');
        const confirmCheckbox = document.getElementById('confirm-checkbox');
        const modalTitle = modal?.querySelector('[data-modal-title]');
        const modalMessage = modal?.querySelector('[data-modal-message]');
        const disableForm = document.createElement('form');
        disableForm.method = 'POST';
        disableForm.action = '{{ route('maintenance.disable') }}';
        disableForm.innerHTML = '@csrf';
        document.body.appendChild(disableForm);

        let pendingAction = 'update';

        function openModal(action) {
            pendingAction = action;
            if (modalTitle) {
                modalTitle.textContent = action === 'disable' ? 'Pengesahan Menyahaktifkan Maintenance' : 'Pengesahan Aktivasi Maintenance';
            }
            if (modalMessage) {
                modalMessage.textContent = action === 'disable'
                    ? 'Anda akan mematikan Maintenance Mode untuk semua pengguna.'
                    : 'Anda akan mengaktifkan Maintenance Mode untuk semua pengguna selain Admin.';
            }
            confirmCheckbox.checked = false;
            confirmButton.disabled = true;
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('overflow-hidden');
        }

        function closeModal() {
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('overflow-hidden');
        }

        saveButton?.addEventListener('click', function () {
            if (!form.reportValidity()) {
                return;
            }
            openModal('update');
        });

        disableButton?.addEventListener('click', function () {
            openModal('disable');
        });

        previewButton?.addEventListener('click', function () {
            const params = new URLSearchParams();
            ['maintenance_enabled', 'maintenance_type', 'maintenance_title', 'maintenance_message', 'maintenance_notes', 'maintenance_end_at', 'system_version'].forEach(function (name) {
                const field = form.querySelector('[name="' + name + '"]');
                if (!field) {
                    return;
                }
                if (field.type === 'checkbox') {
                    params.set(name, field.checked ? '1' : '0');
                    return;
                }
                if (field.value) {
                    params.set(name, field.value);
                }
            });

            const url = '{{ route('maintenance.preview') }}' + (params.toString() ? ('?' + params.toString()) : '');
            window.open(url, '_blank', 'noopener');
        });

        confirmCheckbox?.addEventListener('change', function () {
            confirmButton.disabled = !confirmCheckbox.checked;
        });

        confirmButton?.addEventListener('click', function () {
            if (confirmButton.disabled) {
                return;
            }

            confirmButton.disabled = true;
            confirmButton.textContent = 'Sedang diproses...';

            if (pendingAction === 'disable') {
                disableForm.submit();
                return;
            }

            form.submit();
        });

        cancelButton?.addEventListener('click', closeModal);
        closeBackdrop?.addEventListener('click', closeModal);
    })();
</script>
@endpush
