<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}?v=1.0">
    <link rel="shortcut icon" href="{{ asset('images/favicon.png') }}?v=1.0">
    <link rel="apple-touch-icon" href="{{ asset('images/favicon.png') }}">
    <title>Laporan PPNJ Mill Performance System</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 30px; }
        .report-header { display: flex; align-items: center; gap: 18px; }
        .report-header img { width: 120px; height: auto; }
        .report-header-text { display: block; }
        h1 { color: #0B5D32; font-size: 18px; margin-bottom: 0; }
        p.sub { color: #04501d; margin-top: 2px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1.2px solid #000; padding: 6px 8px; text-align: right; }
        th { background: #fff; color: #000; font-weight: 700; }
        td:first-child, th:first-child { text-align: left; }
        tfoot td {
            font-weight: 700;
            background: #fff;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
        }
        .print-btn { margin: 20px 0; }
        @media print {
            .print-btn { display: none; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="report-header">
        <img src="{{ asset('images/logo-ppnj.jpg') }}" alt="PPNJ Logo">
        <div class="report-header-text">
            <h1>PPNJ MILL PERFORMANCE SYSTEM</h1>
            <p class="sub">Laporan Prestasi Kilang Sawit</p>
            <p><strong>{{ $reportPeriodTitle }}</strong></p>
            <p><strong>Kilang: {{ $reportMillName ?? 'Gabungan Semua Kilang' }}</strong></p>
        </div>
    </div>
    <p>Dijana pada: {{ now()->format('d/m/Y H:i') }}</p>
    @if(isset($operatedDays))
        <p><strong>Hari Operasi: {{ $operatedDays }}/{{ $referenceDays }}</strong></p>
    @endif

    <div class="print-btn">
        <button onclick="window.print()">🖨 Print / Simpan sebagai PDF</button>
    </div>

    <table>
        <thead>
            <tr>
                <th>Tarikh</th>
                <th>BTS Diproses (MT)</th>
                <th>Jualan CPO (MT)</th>
                <th>Jualan PK (MT)</th>
                <th>Pengeluaran CPO (MT)</th>
                <th>Pengeluaran PK (MT)</th>
                <th>Stok CPO Semalam (MT)</th>
                <th>Stok PK Semalam (MT)</th>
                <th>OER (%)</th>
                <th>KER (%)</th>
                <th>Downtime (jam)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($records as $r)
            <tr>
                <td>{{ $r->tarikh->format('d/m/Y') }}</td>
                <td>{{ number_format($r->bts_diproses,2) }}</td>
                <td>{{ number_format($r->pengeluaran_cpo,2) }}</td>
                <td>{{ number_format($r->pengeluaran_pk,2) }}</td>
                <td>{{ number_format($r->produksi_cpo,2) }}</td>
                <td>{{ number_format($r->produksi_pk,2) }}</td>
                <td>{{ number_format($r->stok_cpo_yesterday,2) }}</td>
                <td>{{ number_format($r->stok_pk_yesterday,2) }}</td>
                <td>{{ number_format($r->oer,2) }}</td>
                <td>{{ number_format($r->ker,2) }}</td>
                <td>{{ number_format($r->downtime_jam,2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>JUMLAH</td>
                <td>{{ number_format($records->sum('bts_diproses'),2) }}</td>
                <td>{{ number_format($records->sum('pengeluaran_cpo'),2) }}</td>
                <td>{{ number_format($records->sum('pengeluaran_pk'),2) }}</td>
                <td>{{ number_format($records->sum('produksi_cpo'),2) }}</td>
                <td>{{ number_format($records->sum('produksi_pk'),2) }}</td>
                <td>{{ number_format($records->sum('stok_cpo_yesterday'),2) }}</td>
                <td>{{ number_format($records->sum('stok_pk_yesterday'),2) }}</td>
                <td>{{ number_format($summaryOer,2) }}</td>
                <td>{{ number_format($summaryKer,2) }}</td>
                <td>{{ number_format($records->sum('downtime_jam'),2) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
