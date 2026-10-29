<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <style>
        @page { size: A4 landscape; margin: 12px 16px 22px; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 8px; color: #1f2937; margin: 0; }
        .header { display: table; width: 100%; border-bottom: 2px solid #0B5D32; padding-bottom: 7px; margin-bottom: 8px; }
        .header-cell { display: table-cell; vertical-align: middle; }
        .logo { width: 90px; }
        .logo img { width: 78px; }
        .title { text-align: center; }
        .title h1 { margin: 0; font-size: 15px; color: #0B5D32; }
        .title h2 { margin: 2px 0; font-size: 11px; }
        .title h3 { margin: 4px 0 0; font-size: 13px; }
        .meta { background: #f3f4f6; border: 1px solid #d1d5db; padding: 6px 8px; margin-bottom: 8px; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #d1d5db; padding: 4px; vertical-align: top; word-wrap: break-word; }
        th { background: #0B5D32; color: white; font-size: 7.5px; text-transform: uppercase; }
        tr { page-break-inside: avoid; }
        .muted { color: #6b7280; }
        .change { margin-bottom: 2px; }
        .before { color: #991b1b; }
        .after { color: #047857; }
        .empty { text-align: center; padding: 20px; color: #6b7280; }
        .footer { position: fixed; bottom: -14px; left: 0; right: 0; border-top: 1px solid #d1d5db; padding-top: 4px; color: #6b7280; font-size: 7px; }
        .page-number:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="footer">
        Dokumen dijana melalui Mill Performance System (MPS) PPNJ
        <span style="float:right">Halaman <span class="page-number"></span></span>
    </div>

    <div class="header">
        <div class="header-cell logo">@if($logoDataUri)<img src="{{ $logoDataUri }}" alt="Logo PPNJ">@endif</div>
        <div class="header-cell title">
            <h1>MILL PERFORMANCE SYSTEM (MPS)</h1>
            <h2>PERTUBUHAN PELADANG NEGERI JOHOR</h2>
            <h3>LAPORAN AUDIT TRAIL MPS</h3>
        </div>
        <div class="header-cell" style="width:90px"></div>
    </div>

    <div class="meta">
        <strong>Tempoh:</strong> {{ !empty($filters['tarikh_mula']) ? \Carbon\Carbon::parse($filters['tarikh_mula'])->format('d/m/Y') : 'Semua' }}
        hingga {{ !empty($filters['tarikh_akhir']) ? \Carbon\Carbon::parse($filters['tarikh_akhir'])->format('d/m/Y') : 'Semua' }}
        &nbsp; | &nbsp; <strong>Peranan:</strong> {{ $filters['role'] ?? 'Semua' }}
        &nbsp; | &nbsp; <strong>Tindakan:</strong> {{ $filters['action'] ?? 'Semua' }}
        &nbsp; | &nbsp; <strong>Modul:</strong> {{ $filters['model_type'] ?? 'Semua' }}<br>
        <strong>Dijana oleh:</strong> {{ $generatedBy }}
        &nbsp; | &nbsp; <strong>Tarikh/Masa Dijana:</strong> {{ $generatedAt->format('d/m/Y h:i:s A') }}
        &nbsp; | &nbsp; <strong>Jumlah Rekod:</strong> {{ $logs->count() }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:10%">Tarikh & Masa</th>
                <th style="width:13%">Pengguna / Peranan</th>
                <th style="width:11%">Tindakan</th>
                <th style="width:13%">Modul / Rekod</th>
                <th style="width:41%">Perubahan / Butiran</th>
                <th style="width:12%">IP / Peranti</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
            <tr>
                <td>{{ $log->created_at->format('d/m/Y') }}<br><span class="muted">{{ $log->created_at->format('h:i:s A') }}</span></td>
                <td><strong>{{ $log->user->name ?? 'Pengguna tidak tersedia' }}</strong><br><span class="muted">{{ $log->role_label }}</span></td>
                <td><strong>{{ $log->action_label }}</strong><br><span class="muted">{{ strtoupper($log->severity) }}</span></td>
                <td><strong>{{ $log->module_label }}</strong><br><span class="muted">{{ $log->model_type ?? '-' }}@if($log->model_id) #{{ $log->model_id }}@endif</span></td>
                <td>
                    @forelse($log->changes as $field => $change)
                        <div class="change"><strong>{{ ucwords(str_replace('_', ' ', $field)) }}:</strong>
                            <span class="before">{{ is_array($change['before']) ? json_encode($change['before']) : ($change['before'] ?? '—') }}</span>
                            → <span class="after">{{ is_array($change['after']) ? json_encode($change['after']) : ($change['after'] ?? '—') }}</span>
                        </div>
                    @empty
                        <span class="muted">Tiada perubahan data</span>
                    @endforelse
                </td>
                <td>{{ $log->ip_address ?? '-' }}<br><span class="muted">{{ $log->device_label }}</span></td>
            </tr>
            @empty
            <tr><td colspan="6" class="empty">Tiada rekod audit bagi tempoh dan kriteria yang dipilih.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
