<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <title>Laporan PPNJ Mill Monitoring System</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 30px; }
        h1 { color: #0B5D32; font-size: 18px; margin-bottom: 0; }
        p.sub { color: #C9A227; margin-top: 2px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: right; }
        th { background: #0B5D32; color: white; }
        td:first-child, td:nth-child(2), th:first-child, th:nth-child(2) { text-align: left; }
        tfoot td { font-weight: bold; background: #f5f5f5; }
        .print-btn { margin: 20px 0; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
    <h1>PPNJ MILL MONITORING SYSTEM</h1>
    <p class="sub">Laporan Prestasi Kilang Sawit</p>
    <p>Dijana pada: {{ now()->format('d/m/Y H:i') }}</p>

    <div class="print-btn">
        <button onclick="window.print()">🖨 Print / Simpan sebagai PDF</button>
    </div>

    <table>
        <thead>
            <tr>
                <th>Tarikh</th>
                <th>Kilang</th>
                <th>BTS Diproses (MT)</th>
                <th>CPO (MT)</th>
                <th>PK (MT)</th>
                <th>OER (%)</th>
                <th>KER (%)</th>
                <th>Downtime (jam)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($records as $r)
            <tr>
                <td>{{ $r->tarikh->format('d/m/Y') }}</td>
                <td>{{ $r->mill->name }}</td>
                <td>{{ number_format($r->bts_diproses,2) }}</td>
                <td>{{ number_format($r->pengeluaran_cpo,2) }}</td>
                <td>{{ number_format($r->pengeluaran_pk,2) }}</td>
                <td>{{ number_format($r->oer,2) }}</td>
                <td>{{ number_format($r->ker,2) }}</td>
                <td>{{ number_format($r->downtime_jam,2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">JUMLAH</td>
                <td>{{ number_format($records->sum('bts_diproses'),2) }}</td>
                <td>{{ number_format($records->sum('pengeluaran_cpo'),2) }}</td>
                <td>{{ number_format($records->sum('pengeluaran_pk'),2) }}</td>
                <td>{{ number_format($records->avg('oer'),2) }}</td>
                <td>{{ number_format($records->avg('ker'),2) }}</td>
                <td>{{ number_format($records->sum('downtime_jam'),2) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
