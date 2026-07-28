@extends('layouts.app')
@section('title', 'Input Data Harian')

@section('content')
<div class="bg-white rounded-xl shadow-sm p-6 max-w-4xl">
    <form id="daily-operation-form" method="POST" action="{{ route('data-harian.store') }}" class="space-y-6" data-form-mode="create">
        @csrf

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">A. Maklumat Asas</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tarikh *</label>
                    <input id="tarikh_input" type="date" name="tarikh" max="{{ now()->toDateString() }}" value="{{ old('tarikh', $selectedTarikh ?? now()->toDateString()) }}" onchange="refreshOpeningBalanceByDate()" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Kilang *</label>
                    <select name="mill_id" required class="w-full border rounded-lg px-3 py-2 text-sm" onchange="refreshOpeningBalance(this.value)">
                        <option value="">Pilih Kilang</option>
                        @foreach($mills as $mill)
                            <option value="{{ $mill->id }}" data-code="{{ $mill->code }}" {{ (string)request('mill_id') === (string)$mill->id || (string)old('mill_id') === (string)$mill->id ? 'selected' : '' }}>{{ $mill->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Status Operasi *</label>
                    <select name="operation_status" required class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="Operasi" {{ old('operation_status', 'Operasi') === 'Operasi' ? 'selected' : '' }}>Operasi</option>
                        <option value="Tidak Operasi (Terima Buah Sahaja)" {{ old('operation_status', 'Operasi') === 'Tidak Operasi (Terima Buah Sahaja)' ? 'selected' : '' }}>Tidak Operasi (Terima Buah Sahaja)</option>
                    </select>
                </div>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">B. Penerimaan & Pemprosesan</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">BTS Diterima (MT) *</label>
                    <input type="number" step="0.01" name="bts_diterima" value="{{ old('bts_diterima', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">BTS Diproses (MT) *</label>
                    <input type="number" step="0.01" name="bts_diproses" value="{{ old('bts_diproses', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Baki BTS Semalam (MT)</label>
                    <input type="number" step="0.01" name="baki_bts_semalam" value="{{ old('baki_bts_semalam', $defaultBakiSemalam) }}" @readonly(!($canEditBakiBtsSemalam ?? false)) class="w-full border rounded-lg px-3 py-2 text-sm {{ !($canEditBakiBtsSemalam ?? false) ? 'bg-gray-100' : '' }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Baki BTS Selepas Diproses (MT)</label>
                    <input type="number" step="0.01" name="baki_bts_selepas_diproses" value="{{ old('baki_bts_selepas_diproses', 0) }}" readonly class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-100">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jam Operasi Kilang *</label>
                    <input type="number" step="0.01" name="jam_operasi" value="{{ old('jam_operasi', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Downtime (jam) *</label>
                    <input type="number" step="0.01" name="downtime_jam" value="{{ old('downtime_jam', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-xs text-gray-500 mb-1">Sebab Downtime</label>
                <textarea name="sebab_downtime" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('sebab_downtime') }}</textarea>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">C. Pengeluaran</h3>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jualan CPO (MT) *</label>
                    <input type="number" step="0.01" name="pengeluaran_cpo" value="{{ old('pengeluaran_cpo', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Pengeluaran CPO (MT)</label>
                    <input type="number" step="0.01" name="produksi_cpo" value="{{ old('produksi_cpo', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Stok CPO Semalam (MT)</label>
                    <input type="number" step="0.01" name="stok_cpo_yesterday" value="{{ old('stok_cpo_yesterday', $defaultStokCpoYesterday) }}" @readonly(!($canEditStokCpoYesterday ?? false)) class="w-full border rounded-lg px-3 py-2 text-sm {{ !($canEditStokCpoYesterday ?? false) ? 'bg-gray-100' : '' }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Stok CPO Semasa (MT) *</label>
                    <input type="number" step="0.01" name="stok_cpo" value="{{ old('stok_cpo', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div id="pk_sales_wrap">
                    <label id="label_jualan_pk" class="block text-xs text-gray-500 mb-1">Jualan PK (MT) *</label>
                    <input type="number" step="0.01" name="pengeluaran_pk" value="{{ old('pengeluaran_pk', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div id="pk_hopper_wrap" class="hidden">
                    <label id="label_pk_kcp_to_hopper" class="block text-xs text-gray-500 mb-1">PK KCP to Hopper (MT)</label>
                    <input type="number" step="0.01" name="pk_kcp_to_hopper" value="{{ old('pk_kcp_to_hopper', 0) }}" min="0" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div id="pk_current_wrap">
                    <label id="label_stok_pk_semasa" class="block text-xs text-gray-500 mb-1">Stok PK Semasa (MT) *</label>
                    <input type="number" step="0.01" name="stok_pk" value="{{ old('stok_pk', 0) }}" required class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div id="pk_production_wrap">
                    <label id="label_produksi_pk" class="block text-xs text-gray-500 mb-1">Pengeluaran PK (MT)</label>
                    <input id="produksi_pk_input" type="number" step="0.01" name="produksi_pk" value="{{ old('produksi_pk', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div id="pemindahan_pk_kcp_wrapper" class="hidden md:col-span-2">
                    <label class="block text-xs text-gray-500 mb-1">Pemindahan PK ke KCP (MT)</label>
                    <input id="pemindahan_pk_kcp" type="number" step="0.01" value="0.00" readonly class="w-full border rounded-lg px-3 py-2 text-sm bg-green-50">
                    <p class="text-xs text-gray-400 mt-1">Nilai dipaparkan secara automatik berdasarkan Pengeluaran PK.</p>
                </div>
                <div id="pk_yesterday_wrap">
                    <label id="label_stok_pk_semalam" class="block text-xs text-gray-500 mb-1">Stok PK Semalam (MT)</label>
                    <input type="number" step="0.01" name="stok_pk_yesterday" value="{{ old('stok_pk_yesterday', $defaultStokPkYesterday) }}" @readonly(!($canEditStokPkYesterday ?? false)) class="w-full border rounded-lg px-3 py-2 text-sm {{ !($canEditStokPkYesterday ?? false) ? 'bg-gray-100' : '' }}">
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">* OER dan KER dikira automatik berdasarkan produksi dan BTS diproses. FFA, Moisture dan Dirt masih perlu diisi melalui menu <strong>"Kemaskini Kualiti"</strong>.</p>
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">D. Kualiti</h3>
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">OER (%)</label>
                    <input type="number" step="0.01" name="oer" value="{{ old('oer', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">KER (%)</label>
                    <input type="number" step="0.01" name="ker" value="{{ old('ker', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">FFA (%)</label>
                    <input type="number" step="0.01" name="ffa" value="{{ old('ffa', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Moisture (%)</label>
                    <input type="number" step="0.01" name="moisture" value="{{ old('moisture', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Dirt (%)</label>
                    <input type="number" step="0.01" name="dirt" value="{{ old('dirt', 0) }}" class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
            </div>
        </div>

        <div class="p-4 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 text-sm">
            ℹ️ Data kualiti (FFA, Moisture dan Dirt) akan dikemas kini melalui menu <strong>"Kemaskini Kualiti"</strong> selepas keputusan makmal diterima. Nilai OER, KER, Throughput dan Utilisation dikira secara automatik oleh sistem.
        </div>

        <div>
            <h3 class="text-sm font-semibold ppnj-green-text mb-3 border-b pb-2">E. Catatan</h3>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Isu Operasi</label>
                    <textarea name="isu_operasi" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('isu_operasi') }}</textarea>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tindakan Pembetulan</label>
                    <textarea name="tindakan_pembetulan" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('tindakan_pembetulan') }}</textarea>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Catatan Tambahan</label>
                    <textarea name="catatan_tambahan" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ old('catatan_tambahan') }}</textarea>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-2 border-t">
            <a href="{{ route('rekod-harian.index') }}" class="px-4 py-2 rounded-lg border text-sm">Batal</a>
            <button type="button" id="open-confirmation-modal" class="px-5 py-2 rounded-lg ppnj-green text-white text-sm font-medium">Simpan Data</button>
        </div>
    </form>
</div>

<div id="data-confirmation-modal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-black/50" data-close-confirmation></div>
    <div class="relative z-10 min-h-full flex items-end md:items-center justify-center p-0 md:p-4">
        <div class="w-full md:max-w-6xl bg-white rounded-t-2xl md:rounded-2xl shadow-2xl max-h-[92vh] flex flex-col border border-green-100">
            <div class="px-5 md:px-6 py-4 border-b bg-green-50">
                <h3 class="text-lg font-bold ppnj-green-text">Pengesahan Rekod Harian</h3>
                <p class="text-xs text-gray-600 mt-1">Sila semak semula semua data sebelum dihantar.</p>
            </div>

            <div class="px-5 md:px-6 py-4 overflow-y-auto space-y-4 text-sm">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Kilang</p><p class="font-semibold" data-summary="mill_id">-</p></div>
                    <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Tarikh Operasi</p><p class="font-semibold" data-summary="tarikh">-</p></div>
                    <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Status Operasi</p><p class="font-semibold" data-summary="operation_status">-</p></div>
                </div>

                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Penerimaan dan Pemprosesan</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">BTS Diterima</p><p class="font-semibold" data-summary="bts_diterima">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">BTS Diproses</p><p class="font-semibold" data-summary="bts_diproses">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Baki BTS Semalam</p><p class="font-semibold" data-summary="baki_bts_semalam">-</p></div>
                        <div class="bg-green-50 rounded-lg p-3 border border-green-200"><p class="text-xs text-gray-500">Baki BTS Selepas Diproses</p><p class="font-semibold ppnj-green-text" data-summary="baki_bts_selepas_diproses">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Jam Operasi</p><p class="font-semibold" data-summary="jam_operasi">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Downtime</p><p class="font-semibold" data-summary="downtime_jam">-</p></div>
                    </div>
                </div>

                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Pengeluaran dan Stok</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Jualan CPO</p><p class="font-semibold" data-summary="pengeluaran_cpo">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Jualan PK kepada Pembeli Luar</p><p class="font-semibold" data-summary="pengeluaran_pk">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">PK KCP ke Hopper</p><p class="font-semibold" data-summary="pk_kcp_to_hopper">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Pengeluaran CPO</p><p class="font-semibold" data-summary="produksi_cpo">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Pengeluaran PK</p><p class="font-semibold" data-summary="produksi_pk">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Stok CPO Semalam</p><p class="font-semibold" data-summary="stok_cpo_yesterday">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Stok PK KCP Semalam</p><p class="font-semibold" data-summary="stok_pk_yesterday">-</p></div>
                        <div class="bg-green-50 rounded-lg p-3 border border-green-200"><p class="text-xs text-gray-500">Stok CPO</p><p class="font-semibold ppnj-green-text" data-summary="stok_cpo">-</p></div>
                        <div class="bg-green-50 rounded-lg p-3 border border-green-200"><p class="text-xs text-gray-500">Stok PK KCP</p><p class="font-semibold ppnj-green-text" data-summary="stok_pk">-</p></div>
                    </div>
                </div>

                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Kualiti dan KPI</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="bg-green-50 rounded-lg p-3 border border-green-200"><p class="text-xs text-gray-500">OER</p><p class="font-semibold ppnj-green-text" data-summary="oer">-</p></div>
                        <div class="bg-green-50 rounded-lg p-3 border border-green-200"><p class="text-xs text-gray-500">KER</p><p class="font-semibold ppnj-green-text" data-summary="ker">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">FFA</p><p class="font-semibold" data-summary="ffa">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Moisture</p><p class="font-semibold" data-summary="moisture">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Dirt</p><p class="font-semibold" data-summary="dirt">-</p></div>
                        <div class="bg-green-50 rounded-lg p-3 border border-green-200"><p class="text-xs text-gray-500">Throughput</p><p class="font-semibold ppnj-green-text" data-summary="throughput">-</p></div>
                        <div class="bg-green-50 rounded-lg p-3 border border-green-200"><p class="text-xs text-gray-500">Utilisation</p><p class="font-semibold ppnj-green-text" data-summary="utilisation_rate">-</p></div>
                    </div>
                </div>

                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Catatan</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Sebab Downtime</p><p class="font-semibold" data-summary="sebab_downtime">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Isu Operasi</p><p class="font-semibold" data-summary="isu_operasi">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Tindakan Pembetulan</p><p class="font-semibold" data-summary="tindakan_pembetulan">-</p></div>
                        <div class="bg-gray-50 rounded-lg p-3"><p class="text-xs text-gray-500">Catatan Tambahan</p><p class="font-semibold" data-summary="catatan_tambahan">-</p></div>
                    </div>
                </div>

                <div class="rounded-lg border border-amber-200 bg-amber-50 text-amber-900 p-3 text-sm">
                    Sila pastikan semua maklumat telah disemak berdasarkan Daily Figure kilang. Kesilapan data boleh menjejaskan baki stok, KPI, laporan dan rekod hari berikutnya.
                </div>

                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input id="confirmation-checkbox" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-green-700 focus:ring-green-600">
                    <span>Saya mengesahkan bahawa semua data telah disemak dan adalah tepat.</span>
                </label>
            </div>

            <div class="px-5 md:px-6 py-4 border-t bg-white flex flex-col-reverse md:flex-row md:justify-end gap-2">
                <button type="button" id="close-confirmation-modal" class="px-4 py-2 rounded-lg border text-sm">Kembali Semak Data</button>
                <button type="button" id="confirm-and-submit" disabled class="px-4 py-2 rounded-lg ppnj-green text-white text-sm font-medium disabled:opacity-60 disabled:cursor-not-allowed">Sahkan dan Simpan</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function parseValue(name) {
        const el = document.querySelector('[name="' + name + '"]');
        if (!el) return 0;
        const val = parseFloat(el.value);
        return Number.isFinite(val) ? val : 0;
    }

    function writeValue(name, value) {
        const el = document.querySelector('[name="' + name + '"]');
        if (!el) return;
        el.value = (Math.round(value * 100) / 100).toFixed(2);
    }

    function isBukitBujangSelected() {
        const millSelect = document.querySelector('[name="mill_id"]');
        if (!millSelect) return false;

        const selectedOption = millSelect.options[millSelect.selectedIndex];
        if (!selectedOption) return false;

        return (selectedOption.dataset.code || '').toUpperCase() === 'BBJ';
    }

    function syncPemindahanPkKcp() {
        const transferEl = document.getElementById('pemindahan_pk_kcp');
        if (!transferEl) return;
        transferEl.value = parseValue('produksi_pk').toFixed(2);
    }

    function updatePkLabelsAndTransferVisibility() {
        const isKbb = isBukitBujangSelected();
        const salesWrap = document.getElementById('pk_sales_wrap');
        const hopperWrap = document.getElementById('pk_hopper_wrap');
        const currentWrap = document.getElementById('pk_current_wrap');
        const productionWrap = document.getElementById('pk_production_wrap');
        const yesterdayWrap = document.getElementById('pk_yesterday_wrap');
        const stokSemalamLabel = document.getElementById('label_stok_pk_semalam');
        const jualanPkLabel = document.getElementById('label_jualan_pk');
        const stokSemasaLabel = document.getElementById('label_stok_pk_semasa');
        const hopperLabel = document.getElementById('label_pk_kcp_to_hopper');
        const produksiPkLabel = document.getElementById('label_produksi_pk');
        const produksiPkInput = document.getElementById('produksi_pk_input');
        const transferWrapper = document.getElementById('pemindahan_pk_kcp_wrapper');

        if (salesWrap) {
            salesWrap.style.order = isKbb ? '2' : '';
        }

        if (hopperWrap) {
            hopperWrap.style.order = isKbb ? '3' : '';
            hopperWrap.classList.toggle('hidden', !isKbb);
        }

        if (currentWrap) {
            currentWrap.style.order = isKbb ? '4' : '';
        }

        if (productionWrap) {
            productionWrap.style.order = isKbb ? '5' : '';
        }

        if (yesterdayWrap) {
            yesterdayWrap.style.order = isKbb ? '1' : '';
        }

        if (stokSemalamLabel) {
            stokSemalamLabel.textContent = isKbb ? 'Stok PK KCP Semalam (MT)' : 'Stok PK Semalam (MT)';
        }

        if (jualanPkLabel) {
            jualanPkLabel.textContent = isKbb ? 'Jualan PK kepada Pembeli Luar (MT) *' : 'Jualan PK (MT) *';
        }

        if (stokSemasaLabel) {
            stokSemasaLabel.textContent = isKbb ? 'Stok PK KCP Semasa (MT) *' : 'Stok PK Semasa (MT) *';
        }

        if (hopperLabel) {
            hopperLabel.textContent = 'PK KCP to Hopper (MT)';
        }

        if (produksiPkLabel) {
            produksiPkLabel.textContent = isKbb ? 'Pengeluaran PK (AUTO)' : 'Pengeluaran PK (MT)';
        }

        if (produksiPkInput) {
            produksiPkInput.readOnly = isKbb;
            produksiPkInput.classList.toggle('bg-gray-100', isKbb);
        }

        if (transferWrapper) {
            transferWrapper.classList.toggle('hidden', !isKbb);
            transferWrapper.style.order = isKbb ? '6' : '';
        }

        syncPemindahanPkKcp();
    }

    function setQualityFieldState(isNonOperasi) {
        ['oer', 'ker', 'ffa', 'moisture', 'dirt'].forEach(function (name) {
            const el = document.querySelector('[name="' + name + '"]');
            if (!el) return;

            if (isNonOperasi) {
                writeValue(name, 0);
                el.readOnly = true;
                el.classList.add('bg-gray-100');
            } else {
                el.readOnly = false;
                el.classList.remove('bg-gray-100');
            }
        });
    }

    function recalculateDerivedFields() {
        const isNonOperasi = (document.querySelector('[name="operation_status"]')?.value || '') === 'Tidak Operasi (Terima Buah Sahaja)';
        const isKbb = isBukitBujangSelected();
        setQualityFieldState(isNonOperasi);

        if (isNonOperasi) {
            writeValue('bts_diproses', 0);
            writeValue('jam_operasi', 0);
            writeValue('downtime_jam', 0);

            const bakiBtsNonOperasi = parseValue('baki_bts_semalam') + parseValue('bts_diterima');
            const produksiCpoNonOperasi = parseValue('stok_cpo') - parseValue('stok_cpo_yesterday') + parseValue('pengeluaran_cpo');
            const produksiPkNonOperasi = parseValue('stok_pk') - parseValue('stok_pk_yesterday') + parseValue('pengeluaran_pk') + (isKbb ? parseValue('pk_kcp_to_hopper') : 0);
            writeValue('baki_bts_selepas_diproses', bakiBtsNonOperasi);
            writeValue('produksi_cpo', produksiCpoNonOperasi);
            writeValue('produksi_pk', produksiPkNonOperasi);
            syncPemindahanPkKcp();

            return;
        }

        const produksiCpo = parseValue('stok_cpo') - parseValue('stok_cpo_yesterday') + parseValue('pengeluaran_cpo');
        const produksiPk = parseValue('stok_pk') - parseValue('stok_pk_yesterday') + parseValue('pengeluaran_pk') + (isKbb ? parseValue('pk_kcp_to_hopper') : 0);
        const bakiBts = parseValue('baki_bts_semalam') + parseValue('bts_diterima') - parseValue('bts_diproses');
        const jamOperasi = parseValue('jam_operasi');
        const throughput = jamOperasi > 0 ? (parseValue('bts_diproses') / jamOperasi) : 0;
        const capacity = isKbb ? 30 : 60;
        const utilisation = throughput > 0 ? (throughput / capacity) * 100 : 0;
        const oer = parseValue('bts_diproses') > 0 ? (produksiCpo / parseValue('bts_diproses')) * 100 : 0;
        const ker = parseValue('bts_diproses') > 0 ? (produksiPk / parseValue('bts_diproses')) * 100 : 0;

        writeValue('produksi_cpo', produksiCpo);
        writeValue('produksi_pk', produksiPk);
        writeValue('baki_bts_selepas_diproses', bakiBts);
        writeValue('throughput', throughput);
        writeValue('utilisation_rate', utilisation);
        writeValue('oer', oer);
        writeValue('ker', ker);
        syncPemindahanPkKcp();
    }

    function refreshOpeningBalance(millId) {
        const tarikh = document.getElementById('tarikh_input')?.value || '';
        const params = new URLSearchParams();

        if (millId) {
            params.set('mill_id', millId);
        }
        if (tarikh) {
            params.set('tarikh', tarikh);
        }

        window.location.href = '{{ route('data-harian.create') }}' + (params.toString() ? ('?' + params.toString()) : '');
    }

    function refreshOpeningBalanceByDate() {
        const millId = document.querySelector('[name="mill_id"]')?.value || '';
        refreshOpeningBalance(millId);
    }

    ['operation_status', 'stok_cpo', 'stok_cpo_yesterday', 'pengeluaran_cpo', 'stok_pk', 'stok_pk_yesterday', 'pengeluaran_pk', 'pk_kcp_to_hopper', 'baki_bts_semalam', 'bts_diterima', 'bts_diproses']
        .forEach(function (name) {
            const el = document.querySelector('[name="' + name + '"]');
            if (el) {
                el.addEventListener(name === 'operation_status' ? 'change' : 'input', recalculateDerivedFields);
            }
        });

    const millSelectEl = document.querySelector('[name="mill_id"]');
    if (millSelectEl) {
        millSelectEl.addEventListener('change', updatePkLabelsAndTransferVisibility);
    }

    const produksiPkInput = document.querySelector('[name="produksi_pk"]');
    if (produksiPkInput) {
        produksiPkInput.addEventListener('input', syncPemindahanPkKcp);
    }

    recalculateDerivedFields();
    updatePkLabelsAndTransferVisibility();

    const formEl = document.getElementById('daily-operation-form');
    const modalEl = document.getElementById('data-confirmation-modal');
    const openBtn = document.getElementById('open-confirmation-modal');
    const closeBtn = document.getElementById('close-confirmation-modal');
    const closeBackdrop = modalEl?.querySelector('[data-close-confirmation]');
    const checkbox = document.getElementById('confirmation-checkbox');
    const confirmBtn = document.getElementById('confirm-and-submit');
    let isConfirmedSubmission = false;

    function formatNumber(value, unit) {
        const numeric = Number.parseFloat(value);
        if (!Number.isFinite(numeric)) {
            return '-';
        }
        return numeric.toFixed(2) + (unit ? (' ' + unit) : '');
    }

    function getFieldValue(name) {
        const field = document.querySelector('[name="' + name + '"]');
        if (!field) {
            return '';
        }

        if (field.tagName === 'SELECT') {
            const selected = field.options[field.selectedIndex];
            return selected ? selected.text.trim() : '';
        }

        return (field.value || '').trim();
    }

    function setSummaryValue(name, value) {
        const target = modalEl?.querySelector('[data-summary="' + name + '"]');
        if (target) {
            target.textContent = value && value.length ? value : '-';
        }
    }

    function populateSummary() {
        recalculateDerivedFields();

        const dateValue = getFieldValue('tarikh');
        const formattedDate = dateValue ? new Date(dateValue + 'T00:00:00').toLocaleDateString('ms-MY') : '-';

        setSummaryValue('mill_id', getFieldValue('mill_id'));
        setSummaryValue('tarikh', formattedDate);
        setSummaryValue('operation_status', getFieldValue('operation_status'));

        setSummaryValue('bts_diterima', formatNumber(getFieldValue('bts_diterima'), 'MT'));
        setSummaryValue('bts_diproses', formatNumber(getFieldValue('bts_diproses'), 'MT'));
        setSummaryValue('baki_bts_semalam', formatNumber(getFieldValue('baki_bts_semalam'), 'MT'));
        setSummaryValue('baki_bts_selepas_diproses', formatNumber(getFieldValue('baki_bts_selepas_diproses'), 'MT'));
        setSummaryValue('jam_operasi', formatNumber(getFieldValue('jam_operasi'), 'Jam'));
        setSummaryValue('downtime_jam', formatNumber(getFieldValue('downtime_jam'), 'Jam'));

        setSummaryValue('pengeluaran_cpo', formatNumber(getFieldValue('pengeluaran_cpo'), 'MT'));
        setSummaryValue('pengeluaran_pk', formatNumber(getFieldValue('pengeluaran_pk'), 'MT'));
        setSummaryValue('pk_kcp_to_hopper', formatNumber(getFieldValue('pk_kcp_to_hopper'), 'MT'));
        setSummaryValue('produksi_cpo', formatNumber(getFieldValue('produksi_cpo'), 'MT'));
        setSummaryValue('produksi_pk', formatNumber(getFieldValue('produksi_pk'), 'MT'));
        setSummaryValue('stok_cpo_yesterday', formatNumber(getFieldValue('stok_cpo_yesterday'), 'MT'));
        setSummaryValue('stok_pk_yesterday', formatNumber(getFieldValue('stok_pk_yesterday'), 'MT'));
        setSummaryValue('stok_cpo', formatNumber(getFieldValue('stok_cpo'), 'MT'));
        setSummaryValue('stok_pk', formatNumber(getFieldValue('stok_pk'), 'MT'));

        const btsDiproses = parseValue('bts_diproses');
        const jamOperasi = parseValue('jam_operasi');
        const produksiCpo = parseValue('produksi_cpo');
        const produksiPk = parseValue('produksi_pk');
        const isKbb = isBukitBujangSelected();
        const throughputCalculated = jamOperasi > 0 ? (btsDiproses / jamOperasi) : 0;
        const utilisationCalculated = throughputCalculated > 0 ? (throughputCalculated / (isKbb ? 30 : 60)) * 100 : 0;
        const oerCalculated = btsDiproses > 0 ? (produksiCpo / btsDiproses) * 100 : 0;
        const kerCalculated = btsDiproses > 0 ? (produksiPk / btsDiproses) * 100 : 0;

        setSummaryValue('oer', formatNumber(oerCalculated, '%'));
        setSummaryValue('ker', formatNumber(kerCalculated, '%'));
        setSummaryValue('ffa', formatNumber(getFieldValue('ffa'), '%'));
        setSummaryValue('moisture', formatNumber(getFieldValue('moisture'), '%'));
        setSummaryValue('dirt', formatNumber(getFieldValue('dirt'), '%'));
        setSummaryValue('throughput', formatNumber(throughputCalculated, 'MT/Jam'));
        setSummaryValue('utilisation_rate', formatNumber(utilisationCalculated, '%'));

        setSummaryValue('sebab_downtime', getFieldValue('sebab_downtime'));
        setSummaryValue('isu_operasi', getFieldValue('isu_operasi'));
        setSummaryValue('tindakan_pembetulan', getFieldValue('tindakan_pembetulan'));
        setSummaryValue('catatan_tambahan', getFieldValue('catatan_tambahan'));
    }

    function openModal() {
        if (!formEl?.reportValidity()) {
            return;
        }

        populateSummary();
        checkbox.checked = false;
        confirmBtn.disabled = true;
        modalEl.classList.remove('hidden');
        modalEl.setAttribute('aria-hidden', 'false');
        document.body.classList.add('overflow-hidden');
    }

    function closeModal() {
        modalEl.classList.add('hidden');
        modalEl.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden');
    }

    openBtn?.addEventListener('click', openModal);
    closeBtn?.addEventListener('click', closeModal);
    closeBackdrop?.addEventListener('click', closeModal);

    checkbox?.addEventListener('change', function () {
        confirmBtn.disabled = !checkbox.checked;
    });

    formEl?.addEventListener('submit', function (event) {
        if (isConfirmedSubmission) {
            return;
        }

        event.preventDefault();
        openModal();
    });

    confirmBtn?.addEventListener('click', function () {
        if (confirmBtn.disabled || isConfirmedSubmission) {
            return;
        }

        isConfirmedSubmission = true;
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Sedang menyimpan dan mengira semula data...';
        closeBtn.disabled = true;
        formEl.submit();
    });
</script>
@endpush
