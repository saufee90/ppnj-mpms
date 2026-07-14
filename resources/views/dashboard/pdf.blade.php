<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            size: A4 landscape;
            margin: 16px 18px 18px 18px;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #1f2937;
            margin: 0;
            padding: 0;
        }

        .page {
            width: 100%;
        }

        .header {
            display: table;
            width: 100%;
            border-bottom: 2px solid #0B5D32;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }

        .header-cell {
            display: table-cell;
            vertical-align: middle;
        }

        .header-logo {
            width: 110px;
        }

        .header-logo img {
            width: 96px;
            height: auto;
            display: block;
        }

        .header-title {
            text-align: center;
        }

        .header-title h1 {
            margin: 0;
            font-size: 18px;
            color: #0B5D32;
            letter-spacing: 0.4px;
        }

        .header-title h2 {
            margin: 2px 0 0 0;
            font-size: 14px;
            color: #1f2937;
        }

        .header-title h3 {
            margin: 4px 0 0 0;
            font-size: 15px;
            color: #111827;
        }

        .meta {
            margin-top: 8px;
            font-size: 10px;
            color: #4b5563;
        }

        .meta strong {
            color: #111827;
        }

        .section-title {
            margin: 8px 0 8px 0;
            font-size: 12px;
            font-weight: bold;
            color: #0B5D32;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .two-col {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .two-col td {
            width: 50%;
            vertical-align: top;
            padding-right: 6px;
        }

        .two-col td:last-child {
            padding-right: 0;
            padding-left: 6px;
        }

        .mill-card {
            border: 1px solid #d1d5db;
            border-top: 3px solid #0B5D32;
            border-radius: 6px;
            padding: 8px;
            page-break-inside: avoid;
        }

        .mill-card h4 {
            margin: 0 0 5px 0;
            font-size: 13px;
            color: #111827;
        }

        .status {
            display: inline-block;
            margin-bottom: 6px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: bold;
            color: #0B5D32;
            background-color: #d1fae5;
        }

        .status.offline {
            color: #991b1b;
            background-color: #fee2e2;
        }

        .mill-meta {
            margin: 0 0 6px 0;
            font-size: 10px;
            color: #6b7280;
        }

        .metrics {
            width: 100%;
            border-collapse: collapse;
        }

        .metrics th,
        .metrics td {
            border: 1px solid #d1d5db;
            padding: 5px 6px;
            font-size: 10px;
            text-align: left;
        }

        .metrics th {
            width: 52%;
            background-color: #f3f4f6;
            color: #374151;
            font-weight: bold;
        }

        .metrics td.value {
            width: 48%;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .mtd-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 2px;
        }

        .mtd-table th,
        .mtd-table td {
            border: 1px solid #d1d5db;
            padding: 5px 6px;
            font-size: 10px;
            text-align: center;
        }

        .mtd-table th {
            background-color: #0B5D32;
            color: #ffffff;
            font-weight: bold;
        }

        .mtd-table td.label {
            text-align: left;
            font-weight: bold;
            background-color: #f9fafb;
        }

        .attention {
            margin-top: 10px;
            border: 1px solid #f59e0b;
            background-color: #fff7ed;
            padding: 8px 10px;
            border-radius: 6px;
            page-break-inside: avoid;
        }

        .attention h4 {
            margin: 0 0 5px 0;
            font-size: 12px;
            color: #9a3412;
        }

        .attention ul {
            margin: 0;
            padding-left: 16px;
        }

        .attention li {
            margin: 2px 0;
        }

        .footer {
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px solid #d1d5db;
            text-align: center;
            font-size: 9px;
            color: #6b7280;
            line-height: 1.4;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div class="header-cell header-logo">
                @if(!empty($logoDataUri))
                    <img src="{{ $logoDataUri }}" alt="Logo PPNJ">
                @endif
            </div>
            <div class="header-cell header-title">
                <h1>MILL PERFORMANCE SYSTEM (MPS)</h1>
                <h2>PERTUBUHAN PELADANG NEGERI JOHOR</h2>
                <h3>DASHBOARD PRESTASI HARIAN KILANG</h3>
                <div class="meta">
                    <div><strong>Tarikh Data:</strong> {{ $displayDateText }}</div>
                    <div><strong>Dijana Pada:</strong> {{ $generatedAtText }}</div>
                </div>
            </div>
            <div class="header-cell" style="width:110px;"></div>
        </div>

        <div class="section-title">Ringkasan Harian Dua Kilang</div>
        <table class="two-col">
            <tr>
                @foreach($millSummaries as $summary)
                    <td>
                        <div class="mill-card">
                            <h4>{{ $summary['name'] }}</h4>
                            <div class="status {{ ($summary['status'] ?? 'Tiada Data') === 'Operasi' ? '' : 'offline' }}">
                                {{ $summary['status'] }}
                            </div>
                            <p class="mill-meta">Tarikh data kilang: {{ $summary['data_date_text'] }}</p>
                            <table class="metrics">
                                <tr><th>BTS diterima</th><td class="value">{{ number_format($summary['bts_diterima'] ?? 0, 2) }}</td></tr>
                                <tr><th>BTS diproses</th><td class="value">{{ number_format($summary['bts_diproses'] ?? 0, 2) }}</td></tr>
                                <tr><th>Pengeluaran CPO</th><td class="value">{{ number_format($summary['pengeluaran_cpo'] ?? 0, 2) }}</td></tr>
                                <tr><th>Jualan CPO</th><td class="value">{{ number_format($summary['jualan_cpo'] ?? 0, 2) }}</td></tr>
                                <tr><th>Stok CPO semasa</th><td class="value">{{ number_format($summary['stok_cpo'] ?? 0, 2) }}</td></tr>
                                <tr><th>Pengeluaran PK</th><td class="value">{{ number_format($summary['pengeluaran_pk'] ?? 0, 2) }}</td></tr>
                                <tr><th>Jualan PK</th><td class="value">{{ number_format($summary['jualan_pk'] ?? 0, 2) }}</td></tr>
                                <tr><th>Stok PK semasa</th><td class="value">{{ number_format($summary['stok_pk'] ?? 0, 2) }}</td></tr>
                                <tr><th>OER</th><td class="value">{{ number_format($summary['oer'] ?? 0, 2) }}%</td></tr>
                                <tr><th>KER</th><td class="value">{{ number_format($summary['ker'] ?? 0, 2) }}%</td></tr>
                                <tr><th>Throughput</th><td class="value">{{ number_format($summary['throughput'] ?? 0, 2) }}</td></tr>
                                <tr><th>Downtime</th><td class="value">{{ number_format($summary['downtime'] ?? 0, 2) }}</td></tr>
                                <tr><th>Baki BTS selepas diproses</th><td class="value">{{ number_format($summary['baki_bts_selepas_diproses'] ?? 0, 2) }}</td></tr>
                            </table>
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>

        <div class="section-title" style="margin-top: 10px;">Ringkasan MTD</div>
        <table class="mtd-table">
            <tr>
                <th style="width:28%;">Metrik</th>
                <th style="width:36%;">Kilang Kahang</th>
                <th style="width:36%;">Kilang Bukit Bujang</th>
            </tr>
            <tr>
                <td class="label">BTS diterima MTD</td>
                <td>{{ number_format($millSummaries[0]['mtd']['bts_diterima'] ?? 0, 2) }}</td>
                <td>{{ number_format($millSummaries[1]['mtd']['bts_diterima'] ?? 0, 2) }}</td>
            </tr>
            <tr>
                <td class="label">BTS diproses MTD</td>
                <td>{{ number_format($millSummaries[0]['mtd']['bts_diproses'] ?? 0, 2) }}</td>
                <td>{{ number_format($millSummaries[1]['mtd']['bts_diproses'] ?? 0, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Pengeluaran CPO MTD</td>
                <td>{{ number_format($millSummaries[0]['mtd']['pengeluaran_cpo'] ?? 0, 2) }}</td>
                <td>{{ number_format($millSummaries[1]['mtd']['pengeluaran_cpo'] ?? 0, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Pengeluaran PK MTD</td>
                <td>{{ number_format($millSummaries[0]['mtd']['pengeluaran_pk'] ?? 0, 2) }}</td>
                <td>{{ number_format($millSummaries[1]['mtd']['pengeluaran_pk'] ?? 0, 2) }}</td>
            </tr>
            <tr>
                <td class="label">OER MTD</td>
                <td>{{ number_format($millSummaries[0]['mtd']['oer'] ?? 0, 2) }}%</td>
                <td>{{ number_format($millSummaries[1]['mtd']['oer'] ?? 0, 2) }}%</td>
            </tr>
            <tr>
                <td class="label">KER MTD</td>
                <td>{{ number_format($millSummaries[0]['mtd']['ker'] ?? 0, 2) }}%</td>
                <td>{{ number_format($millSummaries[1]['mtd']['ker'] ?? 0, 2) }}%</td>
            </tr>
            <tr>
                <td class="label">Downtime MTD</td>
                <td>{{ number_format($millSummaries[0]['mtd']['downtime'] ?? 0, 2) }}</td>
                <td>{{ number_format($millSummaries[1]['mtd']['downtime'] ?? 0, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Hari operasi bulan semasa</td>
                <td>{{ number_format($millSummaries[0]['mtd']['hari_operasi'] ?? 0, 0) }}</td>
                <td>{{ number_format($millSummaries[1]['mtd']['hari_operasi'] ?? 0, 0) }}</td>
            </tr>
        </table>

        <div class="attention">
            <h4>PERHATIAN PENGURUSAN</h4>
            <ul>
                @foreach($attentionMessages as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>

        <div class="footer">
            <div>Laporan ini dijana secara automatik melalui</div>
            <div>Mill Performance System (MPS)</div>
            <div>Pertubuhan Peladang Negeri Johor</div>
        </div>
    </div>
</body>
</html>