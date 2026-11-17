<?php

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class FfbExcelTicketParser
{
    public function __construct(private FfbSourceResolver $resolver)
    {
    }

    /**
     * Read-only import preview. Unlabelled weights require caller confirmation
     * ('KG' or 'MT'); explicit workbook units take precedence. Text dates are
     * day-first except ISO dates. File/reader/resolver failures propagate.
     */
    public function parse(
        string $path,
        ?string $confirmedWeightUnit = null,
        ?string $millCode = null,
    ): FfbTicketParseResult
    {
        if ($confirmedWeightUnit !== null && ! in_array($confirmedWeightUnit, ['KG', 'MT'], true)) {
            throw new InvalidArgumentException('Confirmed weight unit must be KG or MT.');
        }

        $format = IOFactory::identify($path, ['Xls', 'Xlsx']);

        $calendar = Date::getExcelCalendar();
        $book = null;
        $tickets = $issues = $groups = $seen = $resolutions = [];

        try {
            $book = IOFactory::createReader($format)->load($path);
            foreach ($book->getAllSheets() as $sheet) {
                $heading = '';
                $headingRow = null;
                $unitEvidence = $this->weightUnits($sheet);
                $unit = count($unitEvidence) === 1 ? $unitEvidence[0] : null;
                $unitOrigin = $unit !== null ? 'workbook' : 'caller';
                if ($unitEvidence === []) {
                    $unit = $confirmedWeightUnit;
                }

                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber = $row->getRowIndex();
                    $raw = $values = [];
                    foreach ($row->getCellIterator() as $cell) {
                        if ($cell->getValue() !== null) {
                            $column = $cell->getColumn();
                            $raw[$column] = $cell->getValue();
                            $values[$column] = $cell->getDataType() === 'f'
                                ? $cell->getOldCalculatedValue() : $cell->getValue();
                        }
                    }

                    if ($raw === []) {
                        continue;
                    }

                    $a = $this->text($values['A'] ?? null);
                    $c = $this->text($values['C'] ?? null);
                    if (preg_match('/^Name\s*:\s*$/i', $a)) {
                        $heading = (string) ($values['C'] ?? '');
                        $headingRow = $rowNumber;
                        $groups[] = [
                            'worksheet' => $sheet->getTitle(), 'row' => $rowNumber,
                            'original_heading' => $heading, 'raw_values' => $raw,
                        ];
                        continue;
                    }
                    if (preg_match('/^Name\s*:\s*(.*)$/i', $c, $match)) {
                        $heading = $match[1];
                        $headingRow = $rowNumber;
                        $groups[] = [
                            'worksheet' => $sheet->getTitle(), 'row' => $rowNumber,
                            'original_heading' => $heading, 'raw_values' => $raw,
                        ];
                        continue;
                    }

                    if ($this->isNonTicketRow($values, $raw)) {
                        continue;
                    }

                    $reasons = [];
                    $identifier = function (string $column) use ($sheet, $rowNumber, $values): string {
                        $value = $values[$column] ?? '';

                        return trim(NumberFormat::toFormattedString(
                            $value,
                            $sheet->getCell($column.$rowNumber)->getStyle()->getNumberFormat()->getFormatCode(),
                        ));
                    };
                    $code = (string) ($values['B'] ?? '');
                    $ticketNumber = $identifier('F');
                    $date = $this->date($values['E'] ?? null, $book->getExcelCalendar());
                    $weight = $this->weight($values['K'] ?? null);

                    foreach (['source_code' => $code, 'ticket_number' => $ticketNumber,
                        'do_number' => $identifier('H'), 'vehicle_number' => $identifier('I')] as $field => $value) {
                        if (trim($value) === '') {
                            $reasons[] = 'missing_'.$field;
                        }
                    }
                    if ($date === null) {
                        $reasons[] = 'invalid_ticket_date';
                    }
                    if ($weight === null) {
                        $reasons[] = 'invalid_nett_weight';
                    }
                    if ($unit === null) {
                        $reasons[] = $unitEvidence === [] ? 'unconfirmed_weight_unit' : 'conflicting_weight_units';
                    }
                    foreach (['B', 'E', 'F', 'H', 'I', 'K'] as $column) {
                        if (isset($raw[$column]) && $sheet->getCell($column.$rowNumber)->getDataType() === 'f'
                            && ($values[$column] ?? null) === null) {
                            $reasons[] = 'formula_without_cached_value_'.$column;
                        }
                    }

                    $key = serialize([$millCode, $heading, $code]);
                    $resolution = $resolutions[$key] ??= $millCode === null
                        ? $this->resolver->resolve($heading, $code)
                        : $this->resolver->resolveForMill($millCode, $heading, $code);
                    if ($resolution->requiresReview) {
                        $reasons[] = 'source_mapping:'.$resolution->reviewReason;
                    }

                    $index = count($tickets);
                    $tickets[] = [
                        'worksheet' => $sheet->getTitle(), 'row' => $rowNumber,
                        'heading_row' => $headingRow, 'original_heading' => $heading,
                        'source_code' => $code, 'ticket_date' => $date,
                        'ticket_number' => $ticketNumber, 'do_number' => $identifier('H'),
                        'vehicle_number' => $identifier('I'), 'raw_weight' => $raw['K'] ?? null,
                        'weight_unit' => $unit, 'weight_unit_origin' => $unit === null ? null : $unitOrigin,
                        'weight_mt' => $weight === null || $unit === null ? null : $weight / ($unit === 'KG' ? 1000 : 1),
                        'source_resolution' => $resolution, 'raw_values' => $raw,
                        'requires_review' => $reasons !== [], 'review_reasons' => $reasons,
                    ];
                    foreach ($reasons as $reason) {
                        $issues[] = $this->issue($tickets[$index], $reason);
                    }
                    if ($ticketNumber !== '') {
                        $duplicateKey = strtoupper($ticketNumber);
                        $seen[$duplicateKey][] = $index;
                    }
                }
            }

            foreach ($seen as $indices) {
                if (count($indices) < 2) {
                    continue;
                }
                foreach ($indices as $index) {
                    $tickets[$index]['requires_review'] = true;
                    $tickets[$index]['review_reasons'][] = 'duplicate_ticket_number';
                    $issue = $this->issue($tickets[$index], 'duplicate_ticket_number');
                    $issue['occurrences'] = array_map(fn (int $i) => [
                        'worksheet' => $tickets[$i]['worksheet'], 'row' => $tickets[$i]['row'],
                    ], $indices);
                    $issues[] = $issue;
                }
            }

            return new FfbTicketParseResult(
                tickets: $tickets,
                ticketCount: count($tickets),
                totalMt: round(array_sum(array_column($tickets, 'weight_mt')), 6),
                sourceGroups: $groups,
                issues: $issues,
                requiresReview: $issues !== [],
                hasCompleteWeightTotal: ! in_array(null, array_column($tickets, 'weight_mt'), true),
            );
        } finally {
            $book?->disconnectWorksheets();
            Date::setExcelCalendar($calendar);
        }
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }

    private function isNonTicketRow(array $values, array $raw): bool
    {
        foreach (['A', 'G'] as $column) {
            if (preg_match('/^(?:Sub\s*Total|(?:Daily\s+)?Grand\s*Total|Total)\s*:?\s*$/i', $this->text($values[$column] ?? null))) {
                return true;
            }
        }
        if (preg_match('/^Ticket\s*(?:No\.?|Number)$/i', $this->text($values['F'] ?? null))
            && strcasecmp($this->text($values['B'] ?? null), 'Code') === 0) {
            return true;
        }
        if (preg_match('/^D\/?O\s*(?:No\.?|Number)$/i', $this->text($values['H'] ?? null))
            && preg_match('/^Vehicle\s*No\.?$/i', $this->text($values['I'] ?? null))) {
            return true;
        }
        $weightLabel = $this->text($values['K'] ?? null);
        if (preg_match('/^(?:NETT?\.?\s*(?:WT|WEIGHT)\b.*|KG|KGS|KILOGRAMS?|MT|METRIC\s+TONNES?|TONNES?)$/i', $weightLabel)
            && ! is_numeric($values['A'] ?? null)
            && array_filter(array_intersect_key($values, array_flip(['B', 'E', 'F', 'H', 'I'])),
                fn ($value) => $this->text($value) !== '') === []) {
            return true;
        }

        foreach (['B', 'E', 'F', 'H', 'I', 'K'] as $column) {
            if ($this->text($values[$column] ?? $raw[$column] ?? null) !== '') {
                return false;
            }
        }

        // A numbered row is still a malformed ticket even if all ticket cells are blank.
        return ! is_numeric($values['A'] ?? null);
    }

    /** @return list<string> */
    private function weightUnits(Worksheet $sheet): array
    {
        $units = [];
        foreach ($sheet->getRowIterator() as $row) {
            $r = $row->getRowIndex();
            foreach (['K', 'L'] as $column) {
                $text = $this->text($sheet->getCell($column.$r)->getValue());
                if (! preg_match('/\bNETT?\.?\s*(?:WT|WEIGHT)\b/i', $text)) {
                    continue;
                }
                $label = $text.' '.$this->text($sheet->getCell('K'.$r)->getValue())
                    .' '.$this->text($sheet->getCell('L'.$r)->getValue());
                if ($r < $sheet->getHighestDataRow()) {
                    $label .= ' '.$this->text($sheet->getCell('K'.($r + 1))->getValue())
                        .' '.$this->text($sheet->getCell('L'.($r + 1))->getValue());
                }
                preg_match_all('/\b(KG|KGS|KILOGRAMS?|MT|METRIC\s+TONNES?|TONNES?)\b/i', $label, $matches);
                foreach ($matches[1] as $match) {
                    $units[] = str_starts_with(strtoupper($match), 'K') ? 'KG' : 'MT';
                }
            }
        }

        return array_values(array_unique($units));
    }

    private function date(mixed $value, int $calendar): ?string
    {
        if (is_int($value) || is_float($value)) {
            if (! is_finite((float) $value) || $value < 1 || $value > 2958465
                || ($calendar === Date::CALENDAR_WINDOWS_1900 && (int) $value === 60)) {
                return null;
            }

            return Date::excelToDateTimeObject($value, calendar: $calendar)->format('Y-m-d');
        }
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'd-M-Y', 'd-M-y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $this->text($value));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function weight(mixed $value): ?float
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $value = $this->text($value);
        if (! preg_match('/^(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d+)?$/D', $value)) {
            return null;
        }
        $weight = (float) str_replace(',', '', $value);

        return is_finite($weight) && $weight > 0 ? $weight : null;
    }

    private function issue(array $ticket, string $reason): array
    {
        return [
            'worksheet' => $ticket['worksheet'], 'row' => $ticket['row'],
            'reason' => $reason, 'raw_values' => $ticket['raw_values'],
            'original_heading' => $ticket['original_heading'],
        ];
    }
}
