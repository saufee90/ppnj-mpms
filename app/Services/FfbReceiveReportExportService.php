<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use App\Support\FfbReceivePresentation;

class FfbReceiveReportExportService
{
    public function filename(array $report, string $extension): string
    {
        $mill = $report['filters']['mill'] === 'BBJ' ? 'KBB' : 'KHG';

        $categories = implode('-', array_map(fn ($value) => match ($value) {
            'ppnj_ppk_integrated' => 'PPK', 'ppnj_owned' => 'PPNJ', 'external_supplier' => 'Luar',
        }, $report['filters']['categories']));

        return 'Penerimaan_BTS_'.$mill.'_'.$categories.'_'.$report['filters']['start'].'_'.$report['filters']['end'].'.'.$extension;
    }

    public function pdf(array $report): string
    {
        $pdf = app('dompdf.wrapper')->loadView('ffb-receive.reports.pdf', compact('report'))
            ->setPaper('a4', 'portrait')->setOption('isRemoteEnabled', false);
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $canvas->page_text(32, $canvas->get_height() - 26,
            'PPNJ | Penerimaan BTS | Disahkan sahaja'.($report['parallel_run_notice'] ? ' | PARALLEL RUN - LATIHAN' : ''),
            $font, 8, [0.2, 0.3, 0.25]);
        $canvas->page_text($canvas->get_width() - 150, $canvas->get_height() - 26,
            'Halaman {PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.2, 0.3, 0.25]);

        return $pdf->output();
    }

    public function excel(array $report): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Pertubuhan Peladang Negeri Johor')
            ->setTitle('Daily FFB Quality Report');
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($report['filters']['mill'] === 'BBJ' ? 'KBB' : 'KHG');
        $hasGoer = collect($report['tickets'])->contains(fn ($ticket) => array_key_exists('AB', $ticket['quality']));
        $lastColumn = $hasGoer ? 'AB' : 'AA';
        foreach ([
            1 => 'PERTUBUHAN PELADANG NEGERI JOHOR',
            2 => 'DAILY FFB QUALITY REPORT',
            3 => $report['mill_name'],
            4 => 'Report Period: '.$report['filters']['start'].' - '.$report['filters']['end'],
        ] as $row => $text) {
            $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
            $sheet->setCellValueExplicit('A'.$row, $text, DataType::TYPE_STRING);
        }
        $sheet->mergeCells('A5:'.$lastColumn.'5');
        $sheet->setCellValueExplicit('A5', $report['category_label'].($report['parallel_run_notice']
            ? ' | PARALLEL RUN (05/11/2026-30/11/2026): latihan, bukan KPI operasi rasmi.'
            : ''), DataType::TYPE_STRING);
        $headers = ['A' => 'Date', 'B' => 'Vehicle No', 'C' => 'Ticket No',
            'D' => 'D/O No', 'E' => 'Code', 'F' => 'Nett WT (MT)', 'G' => 'Unfresh (MT)',
            'X' => 'PLTY', 'Y' => 'OER', 'Z' => 'Reject WT', 'AA' => 'Oil Produced'];
        foreach ($headers as $column => $label) {
            $sheet->mergeCells($column.'6:'.$column.'7');
            $sheet->setCellValueExplicit($column.'6', $label, DataType::TYPE_STRING);
        }
        if ($hasGoer) {
            $sheet->mergeCells('AB6:AB7')->setCellValue('AB6', 'GOER (Original)');
        }
        $sheet->mergeCells('H6:N6')->setCellValue('H6', 'Bunch Weight');
        $sheet->mergeCells('O6:W6')->setCellValue('O6', 'Bunch Quality');
        foreach (['>25', '>10-25', '>8-10', '>6-8', '>4-6', '>3-4', '<3',
            '1', '2', '3', '4', '5', '6', '7', '8', '9'] as $index => $label) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 8).'7', $label, DataType::TYPE_STRING);
        }
        $row = 8;
        $group = null;
        $category = null;
        $groupMicros = 0;
        $totalMicros = 0;
        $count = 0;
        $groupQuality = $totalQuality = [];
        $groupCount = 0;
        $seen = [];
        foreach ($report['tickets'] as $ticket) {
            if (isset($seen[$ticket['id']])) {
                throw new \RuntimeException('Tiket eksport berulang: #'.$ticket['id']);
            }
            $seen[$ticket['id']] = true;
            if ($group !== $ticket['group_id']) {
                if ($count > 0) {
                    $this->subtotal($sheet, $row++, 'Sub Total', $groupMicros, $groupQuality, $groupCount, $lastColumn);
                }
                if ($category !== $ticket['category']) {
                    $category = $ticket['category'];
                    $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
                    $sheet->setCellValueExplicit('A'.$row, FfbReceivePresentation::category($category), DataType::TYPE_STRING);
                    $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFCCE0D0');
                    $sheet->getStyle('A'.$row)->getFont()->setBold(true);
                    $row++;
                }
                $group = $ticket['group_id'];
                $groupMicros = 0;
                $groupQuality = [];
                $groupCount = 0;
                $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
                $sheet->setCellValueExplicit('A'.$row, 'Name: '.$ticket['group_name'], DataType::TYPE_STRING);
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getFont()->setBold(true);
                $row++;
            }
            if ($ticket['date'] !== null) {
                $sheet->setCellValue('A'.$row, Date::PHPToExcel(new \DateTimeImmutable($ticket['date'])));
                $sheet->getStyle('A'.$row)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            }
            foreach (['B' => 'vehicle', 'C' => 'ticket', 'D' => 'do', 'E' => 'code'] as $column => $field) {
                if ($ticket[$field] !== null) {
                    $sheet->setCellValueExplicit($column.$row, $ticket[$field], DataType::TYPE_STRING);
                }
            }
            $sheet->setCellValueExplicit('F'.$row, $ticket['weight_mt'], DataType::TYPE_NUMERIC);
            foreach ($ticket['quality'] as $column => $value) {
                $sheet->setCellValueExplicit($column.$row, $value, DataType::TYPE_NUMERIC);
                if (in_array($column, ['G', 'Z', 'AA'], true)) {
                    $groupQuality[$column]['sum'] = ($groupQuality[$column]['sum'] ?? 0) + $value;
                    $groupQuality[$column]['count'] = ($groupQuality[$column]['count'] ?? 0) + 1;
                    $totalQuality[$column]['sum'] = ($totalQuality[$column]['sum'] ?? 0) + $value;
                    $totalQuality[$column]['count'] = ($totalQuality[$column]['count'] ?? 0) + 1;
                }
            }
            $sheet->getComment('E'.$row)->getText()->createText('Original source: '.$ticket['heading']);
            $micros = (int) round($ticket['weight_mt'] * 1000000);
            $groupMicros += $micros;
            $totalMicros += $micros;
            $count++;
            $groupCount++;
            $row++;
        }
        if ($count !== $report['data']['ticket_count']
            || $totalMicros !== (int) round($report['data']['total_mt'] * 1000000)) {
            throw new \RuntimeException('Jumlah tiket eksport tidak sepadan dengan laporan penerimaan.');
        }
        if ($count > 0) {
            $this->subtotal($sheet, $row++, 'Sub Total', $groupMicros, $groupQuality, $groupCount, $lastColumn);
        }
        $this->subtotal($sheet, $row++, 'Grand Total', $totalMicros, $totalQuality, $count, $lastColumn);
        $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
        $sheet->setCellValueExplicit('A'.$row, 'Confirmed tickets: '.$count.' | Zero-receipt days: '
            .$report['data']['zero_mill_days'].' | Generated: '.$report['generated_at'], DataType::TYPE_STRING);
        $row++;
        $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
        $sheet->setCellValueExplicit('A'.$row,
            'Blank = unavailable. Quality %, PLTY, OER and GOER are original values, not calculated or summed. See Semakan Data for gaps.',
            DataType::TYPE_STRING);
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $sheet->getStyle('A1:AA4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1:AA2')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A6:'.$lastColumn.'7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle('A6:'.$lastColumn.'7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE4EEE6');
        $sheet->getStyle('A6:'.$lastColumn.'7')->getFont()->setBold(true);
        $sheet->getStyle('A6:'.$lastColumn.($row - 2))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('F8:'.$lastColumn.max(8, $row - 2))->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle('A5:'.$lastColumn.'5')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(5)->setRowHeight(30);
        $sheet->getRowDimension(6)->setRowHeight(28);
        $sheet->getRowDimension(7)->setRowHeight(24);
        foreach (range(1, $hasGoer ? 28 : 27) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth($index <= 5 ? 15 : 10);
        }
        $sheet->freezePane('F8');
        $sheet->getPageSetup()->setOrientation('landscape')->setPaperSize(8)->setFitToWidth(1)->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(1, 7)->setPrintArea('A1:'.$lastColumn.$row);
        $sheet->getHeaderFooter()->setOddFooter('&LPPNJ | Daily FFB Quality Report&RPage &P / &N');
        if ($report['quality_issues'] !== [] || $report['quality_mappings'] !== []) {
            $notes = $book->createSheet()->setTitle('Semakan Data');
            $notes->setCellValue('A1', 'Status')->setCellValue('B1', 'Pemetaan / isu sumber');
            $noteRow = 2;
            foreach (['quality_issues' => 'Tidak tersedia / semakan', 'quality_mappings' => 'Pemetaan sumber'] as $key => $label) {
                foreach ($report[$key] as $message) {
                    $notes->setCellValueExplicit('A'.$noteRow, $label, DataType::TYPE_STRING);
                    $notes->setCellValueExplicit('B'.$noteRow++, $message, DataType::TYPE_STRING);
                }
            }
            $notes->getColumnDimension('A')->setWidth(30);
            $notes->getColumnDimension('B')->setWidth(110);
            $notes->getStyle('A1:B'.$noteRow)->getAlignment()->setWrapText(true);
            $notes->getStyle('A1:B1')->getFont()->setBold(true);
            $notes->freezePane('A2');
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function subtotal(Worksheet $sheet, int $row, string $label, int $micros, array $quality, int $count, string $lastColumn): void
    {
        $sheet->mergeCells('A'.$row.':E'.$row);
        $sheet->setCellValueExplicit('A'.$row, $label, DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('F'.$row, $micros / 1000000.0, DataType::TYPE_NUMERIC);
        foreach ($quality as $column => $values) {
            if ($values['count'] === $count) {
                $sheet->setCellValueExplicit($column.$row, $values['sum'], DataType::TYPE_NUMERIC);
            }
        }
        $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getFont()->setBold(true);
        $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F4F1');
    }
}
