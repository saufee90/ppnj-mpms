<?php

namespace Tests\Unit\Services;

use App\Services\FfbExcelTicketParser;
use App\Services\FfbSourceResolution;
use App\Services\FfbSourceResolver;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FfbExcelTicketParserTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    public static function formats(): array
    {
        return [['Xls'], ['Xlsx']];
    }

    #[DataProvider('formats')]
    public function test_content_detection_repeated_pages_subtotals_and_raw_audit(string $format): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $this->headers($sheet, 1, 'NETT. WT (MT)');
        $sheet->fromArray(['Name :', null, ' LADANG BUKIT BUJANG '], null, 'A4');
        $this->ticket($sheet, 5, 'P001', '6.220', '28-10-2026');
        $sheet->setCellValue('H5', 123);
        $sheet->getStyle('H5')->getNumberFormat()->setFormatCode('000000');
        $sheet->setCellValue('G6', 'Sub Total :');
        $sheet->setCellValue('K6', 6.22);
        $this->headers($sheet, 8, 'NETT. WT (MT)');
        $this->ticket($sheet, 12, 'P002', 7.32, Date::PHPToExcel(new \DateTimeImmutable('2026-10-28')));
        $sheet->getStyle('E12')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->setCellValue('G13', 'Daily Grand Total :');
        $sheet->setCellValue('J13', 13.54);
        $sheet->setCellValue('A14', 'Report title');
        $sheet->setCellValue('A15', '  ');
        $sheet->setCellValue('C16', 'Name : UNKNOWN SUPPLIER');
        $this->ticket($sheet, 17, 'P003', 2);

        $result = $this->parser()->parse($this->save($book, $format));

        self::assertSame(3, $result->ticketCount);
        self::assertSame(15.54, $result->totalMt);
        self::assertCount(2, $result->sourceGroups);
        self::assertSame(' LADANG BUKIT BUJANG ', $result->tickets[1]['original_heading']);
        self::assertSame(4, $result->tickets[1]['heading_row']);
        self::assertSame('2026-10-28', $result->tickets[1]['ticket_date']);
        self::assertSame('6.220', $result->tickets[0]['raw_weight']);
        self::assertSame('013A', $result->tickets[0]['source_code']);
        self::assertSame('000123', $result->tickets[0]['do_number']);
        self::assertSame(123, $result->tickets[0]['raw_values']['H']);
        self::assertSame(' JTX 2021 ', $result->tickets[0]['raw_values']['I']);
        self::assertSame('workbook', $result->tickets[0]['weight_unit_origin']);
        self::assertFalse($result->tickets[0]['requires_review']);
        self::assertTrue($result->tickets[2]['requires_review']);
        self::assertNull($result->tickets[2]['source_resolution']->ppk);
        self::assertNull($result->tickets[2]['source_resolution']->source);
        self::assertNull($result->tickets[2]['source_resolution']->sourceCategory);
        self::assertSame('source_mapping:unknown_heading', $result->issues[0]['reason']);
        self::assertSame(17, $result->issues[0]['row']);
        self::assertSame('Worksheet', $result->issues[0]['worksheet']);
        self::assertSame('P003', $result->issues[0]['raw_values']['F']);
        self::assertTrue($result->hasCompleteWeightTotal);
    }

    public function test_all_sheets_duplicates_and_heading_scope(): void
    {
        $book = new Spreadsheet();
        $first = $book->getActiveSheet();
        $first->setTitle('Page 1');
        $first->setCellValue('A1', 'Name :');
        $first->setCellValue('C1', 'KNOWN');
        $this->ticket($first, 2, ' P001 ', 1);
        $second = $book->createSheet()->setTitle('Page 2');
        $this->ticket($second, 3, 'p001', 2);
        $book->createSheet()->setTitle('Notes')->setCellValue('A1', 'Unrelated cover sheet');

        $result = $this->parser()->parse($this->save($book), 'MT');

        self::assertSame(2, $result->ticketCount);
        self::assertSame(3.0, $result->totalMt);
        self::assertSame('', $result->tickets[1]['original_heading']);
        self::assertNull($result->tickets[1]['heading_row']);
        self::assertTrue($result->requiresReview);
        foreach ($result->tickets as $ticket) {
            self::assertTrue($ticket['requires_review']);
            self::assertContains('duplicate_ticket_number', $ticket['review_reasons']);
        }
        $duplicates = array_values(array_filter($result->issues, fn ($issue) => $issue['reason'] === 'duplicate_ticket_number'));
        self::assertCount(2, $duplicates);
        self::assertSame([
            ['worksheet' => 'Page 1', 'row' => 2],
            ['worksheet' => 'Page 2', 'row' => 3],
        ], $duplicates[0]['occurrences']);
    }

    public static function units(): array
    {
        return [
            'explicit kg' => ['NETT. WT (KG)', null, '12,340', 12.34, 'KG', 'workbook'],
            'explicit mt' => ['NETT. WT (MT)', null, 12.34, 12.34, 'MT', 'workbook'],
            'metric tonnes' => ['NETT WEIGHT (metric tonnes)', null, 12.34, 12.34, 'MT', 'workbook'],
            'caller mt' => ['NETT. WT', 'MT', 12.34, 12.34, 'MT', 'caller'],
            'caller kg' => ['NETT. WT', 'KG', 12340, 12.34, 'KG', 'caller'],
            'explicit takes precedence' => ['NETT. WT (KG)', 'MT', 12340, 12.34, 'KG', 'workbook'],
            'no assumption' => ['NETT. WT', null, 12340, null, null, null],
        ];
    }

    #[DataProvider('units')]
    public function test_weight_units(string $label, ?string $confirmed, string|int|float $raw, ?float $mt, ?string $unit, ?string $origin): void
    {
        $book = $this->singleTicket($raw);
        $book->getActiveSheet()->setCellValue('L1', $label);
        $result = $this->parser()->parse($this->save($book), $confirmed);

        self::assertSame($raw, $result->tickets[0]['raw_weight']);
        self::assertSame($mt, $result->tickets[0]['weight_mt']);
        self::assertSame($unit, $result->tickets[0]['weight_unit']);
        self::assertSame($origin, $result->tickets[0]['weight_unit_origin']);
        self::assertSame($mt ?? 0.0, $result->totalMt);
        self::assertSame($mt !== null, $result->hasCompleteWeightTotal);
        if ($unit === null) {
            self::assertContains('unconfirmed_weight_unit', $result->tickets[0]['review_reasons']);
        } else {
            self::assertSame([], $result->issues);
        }
    }

    public function test_adjacent_unit_labels_and_conflicting_repeated_units(): void
    {
        $book = $this->singleTicket(1000);
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('L1', 'NETT. WT');
        $sheet->setCellValue('K2', 'KG');
        $result = $this->parser()->parse($this->save($book));
        self::assertSame(1.0, $result->tickets[0]['weight_mt']);
        self::assertSame(1, $result->ticketCount);

        $sheet->setCellValue('L8', 'NETT. WT (MT)');
        $result = $this->parser()->parse($this->save($book), 'MT');
        self::assertNull($result->tickets[0]['weight_mt']);
        self::assertContains('conflicting_weight_units', $result->tickets[0]['review_reasons']);
    }

    public function test_malformed_rows_remain_visible_with_raw_values_and_locations(): void
    {
        $book = $this->singleTicket('bad weight');
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('E5', '31-02-2026');
        $sheet->setCellValue('F5', null);
        $sheet->setCellValue('H5', null);
        $sheet->setCellValue('I5', null);
        $sheet->setCellValue('A6', 2);
        $sheet->setCellValue('B7', 'ORPHAN CODE');
        $this->ticket($sheet, 8, 'P008', -1);
        $this->ticket($sheet, 9, 'P009', 0);

        $result = $this->parser()->parse($this->save($book), 'MT');

        self::assertSame(5, $result->ticketCount);
        self::assertFalse($result->hasCompleteWeightTotal);
        self::assertSame(0.0, $result->totalMt);
        self::assertContains('missing_ticket_number', $result->tickets[0]['review_reasons']);
        self::assertContains('missing_do_number', $result->tickets[0]['review_reasons']);
        self::assertContains('missing_vehicle_number', $result->tickets[0]['review_reasons']);
        self::assertContains('invalid_ticket_date', $result->tickets[0]['review_reasons']);
        self::assertContains('invalid_nett_weight', $result->tickets[0]['review_reasons']);
        foreach ($result->issues as $issue) {
            self::assertContains($issue['row'], [5, 6, 7, 8, 9]);
            self::assertSame('Worksheet', $issue['worksheet']);
            self::assertNotEmpty($issue['raw_values']);
        }
    }

    public function test_excel_1904_calendar_does_not_change_global_calendar(): void
    {
        $calendar = Date::getExcelCalendar();
        $book = $this->singleTicket(1);
        $book->setExcelCalendar(Date::CALENDAR_MAC_1904);
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('E5', 44861);
        $sheet->getStyle('E5')->getNumberFormat()->setFormatCode('dd-mm-yyyy');
        $result = $this->parser()->parse($this->save($book), 'MT');

        self::assertSame('2026-10-28', $result->tickets[0]['ticket_date']);
        self::assertSame($calendar, Date::getExcelCalendar());
    }

    public function test_uncached_formula_only_row_is_not_silently_discarded(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setCellValue('F3', '="P001"');
        $path = tempnam(sys_get_temp_dir(), 'ffb-parser-');
        $this->files[] = $path;
        IOFactory::createWriter($book, 'Xlsx')->setPreCalculateFormulas(false)->save($path);

        $result = $this->parser()->parse($path, 'MT');

        self::assertSame(1, $result->ticketCount);
        self::assertTrue($result->requiresReview);
        self::assertSame('="P001"', $result->tickets[0]['raw_values']['F']);
        self::assertContains('formula_without_cached_value_F', $result->tickets[0]['review_reasons']);
        self::assertSame(3, $result->tickets[0]['row']);
    }

    public function test_invalid_unit_is_an_explicit_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parser()->parse('unused', 'TON');
    }

    public function test_resolver_receives_original_context_and_is_reused_per_import(): void
    {
        $book = $this->singleTicket(1);
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('C4', '  ORIGINAL HEADING  ');
        $sheet->setCellValue('B5', ' block 01 ');
        $this->ticket($sheet, 6, 'P002', 2);
        $sheet->setCellValue('B6', ' block 01 ');
        $resolution = new FfbSourceResolution('  ORIGINAL HEADING  ', ' block 01 ', true, 'unknown_contextual_code');
        $resolver = $this->createMock(FfbSourceResolver::class);
        $resolver->expects(self::once())->method('resolve')
            ->with('  ORIGINAL HEADING  ', ' block 01 ')->willReturn($resolution);

        $result = (new FfbExcelTicketParser($resolver))->parse($this->save($book), 'MT');

        foreach ($result->tickets as $ticket) {
            self::assertSame($resolution, $ticket['source_resolution']);
            self::assertTrue($ticket['requires_review']);
            self::assertContains('source_mapping:unknown_contextual_code', $ticket['review_reasons']);
        }
    }

    public function test_empty_workbook_has_no_tickets(): void
    {
        $result = $this->parser()->parse($this->save(new Spreadsheet()));

        self::assertSame(0, $result->ticketCount);
        self::assertSame(0.0, $result->totalMt);
        self::assertSame([], $result->issues);
        self::assertSame([], $result->sourceGroups);
        self::assertFalse($result->requiresReview);
    }

    public function test_corrupt_workbook_fails_explicitly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ffb-parser-');
        $this->files[] = $path;
        $this->expectException(\PhpOffice\PhpSpreadsheet\Reader\Exception::class);

        $this->parser()->parse($path, 'MT');
    }

    public function test_actual_local_samples_reconcile_without_modifying_them(): void
    {
        $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'test-samples'.DIRECTORY_SEPARATOR;
        $samples = [
            ['bts 28.10.2026 ksbb.xls', 46, 395.36],
            ['Kilang Kahang 281026.xls', 35, 720.72],
        ];
        foreach ($samples as [$name]) {
            if (! is_file($root.$name)) {
                self::markTestSkipped('Local operational samples are not distributed with the test suite.');
            }
        }

        $count = 0;
        $total = 0.0;
        foreach ($samples as [$name, $expectedCount, $expectedMt]) {
            $hash = hash_file('sha256', $root.$name);
            self::assertSame('Xlsx', IOFactory::identify($root.$name));
            // Reconciliation is independent of database/master-data availability.
            $result = $this->parser(allUnresolved: true)->parse($root.$name, 'MT');
            self::assertSame($expectedCount, $result->ticketCount);
            self::assertSame($expectedMt, $result->totalMt);
            self::assertTrue($result->hasCompleteWeightTotal);
            foreach ($result->tickets as $ticket) {
                self::assertSame('2026-10-28', $ticket['ticket_date']);
                self::assertSame('caller', $ticket['weight_unit_origin']);
                self::assertTrue($ticket['requires_review']);
                self::assertNull($ticket['source_resolution']->source);
            }
            self::assertSame($hash, hash_file('sha256', $root.$name));
            $count += $result->ticketCount;
            $total += $result->totalMt;
        }
        self::assertSame(81, $count);
        self::assertSame(1116.08, round($total, 2));
    }

    private function parser(bool $allUnresolved = false): FfbExcelTicketParser
    {
        $resolver = $this->createMock(FfbSourceResolver::class);
        $resolver->method('resolve')->willReturnCallback(function (string $heading, string $code) use ($allUnresolved) {
            $unknown = $allUnresolved || trim($heading) === '' || str_contains($heading, 'UNKNOWN');

            return new FfbSourceResolution(
                originalHeading: $heading,
                originalCode: $code,
                requiresReview: $unknown,
                reviewReason: $unknown ? 'unknown_heading' : null,
                sourceCategory: $unknown ? null : 'ppnj_owned',
            );
        });

        return new FfbExcelTicketParser($resolver);
    }

    private function singleTicket(string|int|float $weight): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A4', 'Name :');
        $sheet->setCellValue('C4', 'KNOWN');
        $this->ticket($sheet, 5, 'P001', $weight);

        return $book;
    }

    private function headers(Worksheet $sheet, int $row, string $label): void
    {
        $sheet->setCellValue('A'.$row, 'DAILY FFB INTAKE');
        $sheet->setCellValue('H'.($row + 1), 'D/O Number');
        $sheet->setCellValue('I'.($row + 1), 'Vehicle No');
        $sheet->setCellValue('L'.($row + 1), $label);
        $sheet->setCellValue('A'.($row + 2), 'No ');
        $sheet->setCellValue('B'.($row + 2), 'Code');
        $sheet->setCellValue('E'.($row + 2), 'Date');
        $sheet->setCellValue('F'.($row + 2), 'Ticket No');
    }

    private function ticket(Worksheet $sheet, int $row, string $number, string|int|float $weight, string|float $date = '28-10-2026'): void
    {
        $sheet->setCellValue('A'.$row, 1);
        $sheet->setCellValue('B'.$row, '013A');
        $sheet->setCellValue('E'.$row, $date);
        $sheet->setCellValue('F'.$row, $number);
        $sheet->setCellValue('H'.$row, '0010835');
        $sheet->setCellValue('I'.$row, ' JTX 2021 ');
        $sheet->setCellValueExplicit('K'.$row, $weight, is_string($weight) ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
    }

    private function save(Spreadsheet $book, string $format = 'Xlsx'): string
    {
        // An extensionless fixture forces content-based reader selection.
        $path = tempnam(sys_get_temp_dir(), 'ffb-parser-');
        $this->files[] = $path;
        IOFactory::createWriter($book, $format)->save($path);

        return $path;
    }
}
