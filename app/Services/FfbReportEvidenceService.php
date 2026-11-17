<?php

namespace App\Services;

use App\Models\FfbImportBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FfbReportEvidenceService
{
    public const LABELS = [
        'G' => 'Unfresh (MT)', 'H' => '>25', 'I' => '>10-25', 'J' => '>8-10',
        'K' => '>6-8', 'L' => '>4-6', 'M' => '>3-4', 'N' => '<3',
        'O' => 'Bunch Quality 1', 'P' => 'Bunch Quality 2', 'Q' => 'Bunch Quality 3',
        'R' => 'Bunch Quality 4', 'S' => 'Bunch Quality 5', 'T' => 'Bunch Quality 6',
        'U' => 'Bunch Quality 7', 'V' => 'Bunch Quality 8', 'W' => 'Bunch Quality 9',
        'X' => 'PLTY', 'Y' => 'OER', 'Z' => 'Reject WT', 'AA' => 'Oil Produced', 'AB' => 'GOER',
    ];

    public function extract(FfbImportBatch $batch, Collection $tickets): array
    {
        $path = Storage::disk($batch->storage_disk)->path($batch->storage_path);
        if (! is_file($path) || ! hash_equals($batch->file_sha256, hash_file('sha256', $path))) {
            $this->fail($batch, 'Bukti Excel hilang atau SHA256 tidak sepadan.');
        }
        $calendar = Date::getExcelCalendar();
        $book = null;
        try {
            $format = IOFactory::identify($path, ['Xls', 'Xlsx']);
            $book = IOFactory::createReader($format)->load($path);
            $result = ['tickets' => [], 'issues' => [], 'mappings' => []];
            foreach ($tickets->groupBy('worksheet') as $name => $sheetTickets) {
                $sheet = $book->getSheetByName($name);
                if ($sheet === null) {
                    $this->fail($batch, "Helaian {$name} tidak ditemui.");
                }
                $profile = $this->profile($sheet);
                if ($profile === null) {
                    $this->fail($batch, "Helaian {$name}: header tidak dikenali. Pemetaan kualiti tidak boleh diteka.");
                }
                $rows = [];
                foreach ($sheet->getRowIterator($profile['header_row'] + 1) as $row) {
                    $r = $row->getRowIndex();
                    $date = $this->date($this->value($sheet, $profile['identity']['date'], $r), $book->getExcelCalendar());
                    $number = $this->identifier($sheet, $profile['identity']['ticket'], $r);
                    if ($date === null || $number === '') {
                        continue;
                    }
                    $identity = ['date' => $date, 'ticket' => $number];
                    foreach (['code', 'do', 'vehicle'] as $field) {
                        $identity[$field] = $this->identifier($sheet, $profile['identity'][$field], $r);
                    }
                    $rows[$this->key($identity)][] = $r;
                }
                $used = [];
                foreach ($sheetTickets as $ticket) {
                    $key = $this->key([
                        'date' => $ticket->ticket_date?->toDateString(), 'ticket' => $ticket->ticket_number,
                        'code' => $ticket->original_source_code, 'do' => $ticket->do_number, 'vehicle' => $ticket->vehicle_number,
                    ]);
                    $matches = $rows[$key] ?? [];
                    if (count($matches) !== 1 || isset($used[$matches[0]])) {
                        $this->fail($batch, "Helaian {$name}, tiket {$ticket->ticket_number}: padanan identiti "
                            .'(tarikh, tiket, kod, D/O, kenderaan) tiada atau tidak unik.');
                    }
                    $r = $matches[0];
                    $used[$r] = true;
                    $net = $this->number($this->value($sheet, $profile['net'], $r));
                    $netMt = $net === null ? null : $net / ($ticket->weight_unit === 'KG' ? 1000 : 1);
                    if ($netMt === null || (int) round($netMt * 1000000) !== (int) round((float) $ticket->weight_mt * 1000000)) {
                        $this->fail($batch, "Tiket {$ticket->ticket_number}: berat bukti tidak sepadan dengan tiket disahkan.");
                    }
                    $quality = [];
                    foreach ($profile['fields'] as $output => $column) {
                        $raw = $ticket->raw_values;
                        $original = $sheet->getCell($column.$r)->getValue();
                        $stored = $raw[$column] ?? null;
                        if ($stored !== null && $stored !== $original
                            && ! ($this->number($stored) !== null && $this->number($stored) === $this->number($original))) {
                            $this->fail($batch, "Tiket {$ticket->ticket_number}: raw_values {$column} berbeza daripada bukti.");
                        }
                        $value = $stored ?? $this->value($sheet, $column, $r);
                        if (is_string($value) && str_starts_with($value, '=')) {
                            $value = $this->value($sheet, $column, $r);
                        }
                        $numeric = $this->number($value);
                        if ($numeric !== null) {
                            $quality[$output] = $numeric;
                        } elseif ($value !== null && trim((string) $value) !== '') {
                            $result['issues'][] = "Batch #{$batch->id}, tiket {$ticket->ticket_number}, {$column}: nilai bukan angka; dibiarkan kosong.";
                        } elseif (is_string($original) && str_starts_with($original, '=')) {
                            $result['issues'][] = "Batch #{$batch->id}, tiket {$ticket->ticket_number}, {$column}: formula tiada nilai cache; tidak dikira semula.";
                        }
                    }
                    $result['tickets'][$ticket->id] = $quality;
                }
                foreach ($profile['fields'] as $output => $column) {
                    $result['mappings'][] = "Batch #{$batch->id} / {$name}: {$column} -> {$output} (".self::LABELS[$output].')';
                }
                foreach (array_diff_key(self::LABELS, $profile['fields']) as $output => $label) {
                    if ($output !== 'AB') {
                        $result['issues'][] = "Batch #{$batch->id} / {$name}: {$label} tiada header sumber yang disahkan; lajur {$output} kosong.";
                    }
                }
            }
            $result['issues'] = array_values(array_unique($result['issues']));

            return $result;
        } finally {
            $book?->disconnectWorksheets();
            Date::setExcelCalendar($calendar);
        }
    }

    private function profile(Worksheet $sheet): ?array
    {
        // Fragmented intake export: labels and payload occupy different columns.
        // Require the complete header fingerprint, not a mill code or filename.
        $fingerprint = [
            'B' => 'Code', 'E' => 'Date', 'F' => 'Ticket No', 'N' => 'GOER',
            'P' => '>25', 'R' => '>10-25', 'T' => '>8-10', 'W' => '>6-8',
            'Y' => '>4-6', 'AA' => '>3-4', 'AC' => '<3', 'AD' => 'FS', 'AE' => 'UD',
            'AF' => 'EM', 'AG' => 'RB', 'AH' => 'RB', 'AI' => 'LS', 'AJ' => 'DB',
            'AL' => 'DD', 'AM' => 'OB', 'AO' => 'OER', 'AP' => 'PLTY', 'AQ' => 'Produced', 'AT' => 'Rej',
        ];
        for ($r = 2; $r <= min(100, $sheet->getHighestDataRow()); $r++) {
            $match = true;
            foreach ($fingerprint as $column => $label) {
                if ($this->normalize($sheet->getCell($column.$r)->getValue()) !== $this->normalize($label)) {
                    $match = false;
                    break;
                }
            }
            if ($match && $this->normalize($sheet->getCell('H'.($r - 1))->getValue()) === 'd/onumber'
                && $this->normalize($sheet->getCell('I'.($r - 1))->getValue()) === 'vehicleno'
                && $this->normalize($sheet->getCell('Q'.($r - 1))->getValue()) === 'bunchweight'
                && $this->normalize($sheet->getCell('AD'.($r - 1))->getValue()) === 'bunchquality'
                && $this->normalize($sheet->getCell('AR'.($r - 1))->getValue()) === 'oil') {
                return [
                    'header_row' => $r, 'identity' => ['date' => 'E', 'ticket' => 'F', 'code' => 'B', 'do' => 'H', 'vehicle' => 'I'],
                    'net' => 'K',
                    'fields' => [
                        'H' => 'O', 'I' => 'R', 'J' => 'S', 'K' => 'U', 'L' => 'X', 'M' => 'Z', 'N' => 'AB',
                        'O' => 'AD', 'P' => 'AE', 'Q' => 'AF', 'R' => 'AG', 'S' => 'AH',
                        'T' => 'AI', 'U' => 'AJ', 'V' => 'AK', 'W' => 'AM',
                        'X' => 'AP', 'Y' => 'AN', 'Z' => 'AS', 'AA' => 'AR', 'AB' => 'M',
                    ],
                ];
            }
        }

        return $this->alignedProfile($sheet);
    }

    private function alignedProfile(Worksheet $sheet): ?array
    {
        $aliases = [
            'date' => ['date', 'tarikh'], 'vehicle' => ['vehicleno', 'vehiclenumber'],
            'ticket' => ['ticketno', 'ticketnumber'], 'do' => ['d/ono', 'd/onumber', 'dono', 'donumber'],
            'code' => ['code', 'kod'], 'net' => ['nettwt', 'nettwt(mt)', 'nettweight', 'nettweight(mt)', 'nettwt(kg)'],
            'G' => ['unfresh(mt)'], 'X' => ['plty'], 'Y' => ['oer'], 'Z' => ['rejectwt', 'rej'],
            'AA' => ['oilproduced'], 'AB' => ['goer'],
        ];
        for ($r = 1; $r <= min(100, $sheet->getHighestDataRow()); $r++) {
            $found = [];
            $weights = [];
            $qualities = [];
            $group = '';
            $width = min(200, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
            for ($c = 1; $c <= $width; $c++) {
                $column = Coordinate::stringFromColumnIndex($c);
                $text = $this->normalize($sheet->getCell($column.$r)->getValue());
                $above = $r > 1 ? $this->normalize($sheet->getCell($column.($r - 1))->getValue()) : '';
                if (in_array($above, ['bunchweight', 'bunchquality'], true)) {
                    $group = $above;
                }
                foreach ($aliases as $key => $labels) {
                    if (in_array($text, $labels, true) || in_array($above, $labels, true)) {
                        $found[$key][] = $column;
                    }
                }
                $weightIndex = array_search($text, ['>25', '>10-25', '>8-10', '>6-8', '>4-6', '>3-4', '<3'], true);
                if ($weightIndex !== false) {
                    $weights[Coordinate::stringFromColumnIndex($weightIndex + 8)][] = $column;
                }
                if ($group === 'bunchquality' && preg_match('/^[1-9]$/D', $text)) {
                    $qualities[Coordinate::stringFromColumnIndex((int) $text + 14)][] = $column;
                }
            }
            if (count(array_intersect(['date', 'vehicle', 'ticket', 'do', 'code', 'net'], array_keys($found))) !== 6) {
                continue;
            }
            foreach ($found as $matches) {
                if (count(array_unique($matches)) !== 1) {
                    return null;
                }
            }
            $identity = [];
            foreach (['date', 'vehicle', 'ticket', 'do', 'code'] as $key) {
                $identity[$key] = $found[$key][0];
            }
            $fields = [];
            foreach (array_merge(array_intersect_key($found, self::LABELS), $weights, $qualities) as $key => $matches) {
                if (count(array_unique($matches)) === 1) {
                    $fields[$key] = $matches[0];
                }
            }

            return ['header_row' => $r, 'identity' => $identity, 'net' => $found['net'][0], 'fields' => $fields];
        }

        return null;
    }

    private function identifier(Worksheet $sheet, string $column, int $row): string
    {
        return trim(NumberFormat::toFormattedString($this->value($sheet, $column, $row) ?? '',
            $sheet->getStyle($column.$row)->getNumberFormat()->getFormatCode()));
    }

    private function value(Worksheet $sheet, string $column, int $row): mixed
    {
        $cell = $sheet->getCell($column.$row);

        return $cell->getDataType() === 'f' ? $cell->getOldCalculatedValue() : $cell->getValue();
    }

    private function date(mixed $value, int $calendar): ?string
    {
        if (is_numeric($value) && (float) $value >= 1 && (float) $value <= 2958465) {
            return Date::excelToDateTimeObject((float) $value, calendar: $calendar)->format('Y-m-d');
        }
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'd-M-Y', 'd-M-y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, trim((string) $value));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function key(array $identity): string
    {
        return json_encode(array_map(fn ($field) => strtoupper(trim((string) ($identity[$field] ?? ''))),
            ['date', 'ticket', 'code', 'do', 'vehicle']), JSON_THROW_ON_ERROR);
    }

    private function number(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }
        if (is_string($value)) {
            $value = trim($value);
            if (! preg_match('/^-?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d+)?%?$/D', $value)) {
                return null;
            }
            $value = str_replace([',', '%'], '', $value);
        }

        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function normalize(mixed $value): string
    {
        return strtolower(preg_replace('/[\s.]+/u', '', (string) $value));
    }

    private function fail(FfbImportBatch $batch, string $message): never
    {
        throw ValidationException::withMessages(['report' => "Batch #{$batch->id}: {$message}"]);
    }
}
