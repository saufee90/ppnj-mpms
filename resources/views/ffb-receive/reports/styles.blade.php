<style>
    body > .flex > .flex-1 { min-width: 0; }
    .bts-report { color: #233b30; font-family: "DejaVu Sans", Arial, sans-serif; font-size: 12px; line-height: 1.55; }
    .bts-report * { box-sizing: border-box; }
    .bts-report .report-cover { background: #0b5d32; color: #fff; padding: 18px; border-bottom: 3px solid #c9a227; border-radius: 10px 10px 0 0; }
    .bts-report .eyebrow { text-transform: uppercase; letter-spacing: 2px; font-size: 10px; color: #eed58a; }
    .bts-report h1 { font-size: 25px; margin: 8px 0; line-height: 1.25; }
    .bts-report h2 { font-size: 17px; color: #0b5d32; border-bottom: 2px solid #c9a227; padding-bottom: 8px; margin: 0 0 14px; }
    .bts-report h3 { font-size: 13px; color: #0b5d32; margin: 12px 0 8px; }
    .bts-report p { margin: 6px 0; }
    .bts-report .report-section { background: #fff; padding: 16px; margin-bottom: 12px; border: 1px solid #e0e9e3; border-radius: 0 0 10px 10px; }
    .bts-report .muted { color: #63766a; font-size: 10px; }
    .bts-report .badge { display: inline-block; padding: 4px 10px; background: #e8f4ec; color: #0b5d32; border-radius: 12px; font-size: 10px; font-weight: bold; }
    .bts-report table { width: 100%; border-collapse: collapse; font-size: 11px; }
    .bts-report thead { display: table-header-group; }
    .bts-report th { background: #eaf1ec; text-align: left; color: #0b5d32; padding: 9px 7px; border-bottom: 2px solid #c9a227; }
    .bts-report td { padding: 8px 7px; border-bottom: 1px solid #e6ece8; vertical-align: top; overflow-wrap: anywhere; }
    .bts-report tbody tr:nth-child(even) { background: #f7faf8; }
    .bts-report .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .bts-report .total td { font-weight: bold; background: #eaf1ec; }
    .bts-report .kpi-table td { width: 33.33%; background: #f4f8f5; border: 5px solid #fff; padding: 16px; }
    .bts-report .kpi-value { display: block; font-size: 24px; color: #0b5d32; font-weight: bold; }
    .bts-report .chart { width: 100%; height: auto; max-height: 250px; object-fit: contain; }
    .bts-report .table-scroll { max-width: 100%; overflow-x: auto; }
    .bts-report .register { font-size: 9px; table-layout: fixed; }
    .bts-report .register td, .bts-report .register th { padding: 7px 4px; word-wrap: break-word; }
    .bts-report .hash { font-size: 8px; word-break: break-all; word-wrap: break-word; }
    .bts-report ol { padding-left: 20px; }
    .bts-report li { margin-bottom: 8px; }
    @media(max-width: 640px) {
        .bts-report .report-section, .bts-report .report-cover { padding: 16px; }
        .bts-report h1 { font-size: 22px; }
        .bts-report .kpi-value { font-size: 18px; }
        .bts-report .kpi-table td { padding: 8px; }
        .bts-report .register { min-width: 1050px; }
    }
    @media print {
        .bts-report .report-section { border: 0; padding: 0; margin-top: 20px; }
        .bts-report tr, .bts-report .chart { page-break-inside: avoid; }
        .bts-report h2, .bts-report h3 { page-break-after: avoid; }
        .bts-report .page-break { page-break-before: always; }
        .bts-report .table-scroll { overflow: visible; }
    }
</style>
