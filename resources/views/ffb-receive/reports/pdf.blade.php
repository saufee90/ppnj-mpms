<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] }}</title>
    @include('ffb-receive.reports.styles')
    <style>
        @page { margin: 30px 32px 48px; }
        body { margin: 0; }
        .bts-report { font-size: 10px; }
        .bts-report .report-section { padding: 12px 0; border: 0; margin-bottom: 12px; }
        .bts-report h1 { font-size: 23px; }
        .bts-report h2 { font-size: 15px; page-break-after: avoid; }
        .bts-report h3 { page-break-after: avoid; }
        .bts-report table { font-size: 9px; }
        .bts-report tr { page-break-inside: avoid; }
        .bts-report .chart { width: 100%; height: 190px; page-break-inside: avoid; }
        .bts-report .page-break { page-break-before: always; }
        .bts-report .table-scroll { overflow: visible; }
        .bts-report .register { font-size: 7px; }
        .bts-report .register td, .bts-report .register th { padding: 5px 3px; }
    </style>
</head>
<body>
    @include('ffb-receive.reports.content')
</body>
</html>
