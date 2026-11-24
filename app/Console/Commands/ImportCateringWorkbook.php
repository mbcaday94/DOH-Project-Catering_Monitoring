<?php

namespace App\Console\Commands;

use App\Http\Controllers\CateringDashboardController;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ImportCateringWorkbook extends Command
{
    protected $signature = 'catering:import {workbook : Path to the XLSX catering workbook}';

    protected $description = 'Import purchase orders, billing entries, supplier evaluations, and notices to conduct';

    private const MONTHS = [
        'JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE',
        'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER',
    ];

    public function handle(): int
    {
        $path = $this->argument('workbook');
        $archive = new ZipArchive;

        if (! is_file($path) || $archive->open($path, ZipArchive::RDONLY) !== true) {
            $this->error('Could not open the specified XLSX workbook.');

            return self::FAILURE;
        }

        try {
            $records = $this->readWorkbook($archive, basename($path));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $archive->close();
        }

        if ($records === []) {
            $this->error('No catering records were found.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($records): void {
            $editedRows = DB::table('catering_records')
                ->where('source_file', $records[0]['source_file'])
                ->where(function ($query): void {
                    $query->where('kind', 'order')->whereNotNull('province')
                        ->orWhere(fn ($query) => $query->where('kind', 'ntc')
                            ->whereNotNull('details->'.CateringDashboardController::NTC_EDITED))
                        ->orWhere(fn ($query) => $query->where('kind', 'billing')
                            ->whereNotNull('details->'.CateringDashboardController::BILLING_EDITED));
                })
                ->lockForUpdate()
                ->get(['source_sheet', 'source_row'])
                ->groupBy('source_sheet')
                ->map(fn ($rows) => $rows->pluck('source_row')->flip());

            foreach (array_chunk($records, 100) as $batch) {
                $unchangedRows = array_filter($batch, fn (array $row): bool => ! isset($editedRows[$row['source_sheet']][$row['source_row']]));
                if ($unchangedRows !== []) {
                    DB::table('catering_records')->upsert($unchangedRows, ['source_file', 'source_sheet', 'source_row']);
                }
            }
        });

        foreach (array_count_values(array_column($records, 'kind')) as $kind => $count) {
            $this->line("$kind: $count");
        }

        return self::SUCCESS;
    }

    private function readWorkbook(ZipArchive $archive, string $source): array
    {
        $strings = [];
        if ($archive->locateName('xl/sharedStrings.xml') !== false) {
            foreach ($this->xml($archive, 'xl/sharedStrings.xml')->si as $string) {
                $strings[] = $this->text($string);
            }
        }

        $styles = $this->xml($archive, 'xl/styles.xml');
        $dateFormats = [14, 15, 16, 17, 22];
        foreach ($styles->numFmts->numFmt ?? [] as $format) {
            if (preg_match('/[dy]/i', preg_replace('/"[^"]*"|\\\\./', '', (string) $format['formatCode']))) {
                $dateFormats[] = (int) $format['numFmtId'];
            }
        }

        $dateStyles = [];
        foreach ($styles->cellXfs->xf as $style) {
            $dateStyles[] = in_array((int) $style['numFmtId'], $dateFormats, true);
        }

        $workbook = $this->xml($archive, 'xl/workbook.xml');
        $epoch = in_array((string) ($workbook->workbookPr['date1904'] ?? ''), ['1', 'true'], true)
            ? new DateTimeImmutable('1904-01-01')
            : new DateTimeImmutable('1899-12-30');
        $targets = [];
        foreach ($this->xml($archive, 'xl/_rels/workbook.xml.rels')->Relationship as $relationship) {
            $targets[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        $records = [];
        foreach ($workbook->sheets->sheet as $sheet) {
            $name = trim((string) $sheet['name']);
            $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $targets[$id] ?? '';
            if (! preg_match('~^(?:/?xl/)?(worksheets/[^/]+\.xml)$~', $target, $match)) {
                continue;
            }

            $headers = [];
            $kind = null;
            $period = null;
            $order = null;
            foreach ($this->xml($archive, 'xl/'.$match[1])->sheetData->row as $row) {
                $number = (int) $row['r'];
                [$cells, $dates] = $this->readRow($row, $strings, $dateStyles, $epoch);

                if (($cells['B'] ?? '') === 'CATERER' && ($cells['C'] ?? '') === 'PO NUMBER') {
                    $headers = $cells;
                    $kind = 'order';
                } elseif (str_contains($cells['B'] ?? '', 'ORDER/CONTRACT') && ($cells['H'] ?? '') === 'CATERER') {
                    $headers = $cells;
                    $kind = 'evaluation';
                } elseif (($cells['B'] ?? '') === 'NTC NO.' && ($cells['E'] ?? '') === 'SUPPLIER') {
                    $headers = $cells;
                    $kind = 'ntc';
                } elseif ($kind !== null) {
                    $marker = strtoupper($cells['A'] ?? '');
                    if ($kind === 'order' && in_array($marker, self::MONTHS, true)) {
                        $period = ucfirst(strtolower($marker));
                        $order = null;

                        continue;
                    }

                    if ($kind === 'ntc' && is_numeric($cells['D'] ?? null) && $cells['D'] >= 0 && $cells['D'] < 1) {
                        $cells['D'] = gmdate('g:i A', (int) floor($cells['D'] * 86400 + 0.5));
                    }

                    $record = match ($kind) {
                        'evaluation' => $this->evaluation($cells, $dates, $name),
                        'ntc' => $this->notice($cells, $dates),
                        default => $this->purchase($cells, $dates, $period, $order, $number),
                    };

                    if ($record !== null) {
                        $detailCells = array_intersect_key($cells, $headers);
                        $details = [];
                        foreach ($detailCells as $column => $value) {
                            $label = preg_replace('/\s+/u', ' ', $headers[$column]);
                            $details[$label.' ('.$column.')'] = $value;
                        }
                        $records[] = $record + [
                            'source_file' => $source, 'source_sheet' => $name, 'source_row' => $number,
                            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                        ];
                    }
                }
            }
        }

        return $records;
    }

    private function xml(ZipArchive $archive, string $path): SimpleXMLElement
    {
        $content = $archive->getFromName($path);
        $xml = $content === false ? false : @simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);

        if ($xml === false) {
            throw new RuntimeException("Cannot read workbook part: $path");
        }

        return $xml;
    }

    private function text(SimpleXMLElement $node): string
    {
        $node->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return implode('', $node->xpath('x:t | x:r/x:t') ?: []);
    }

    private function readRow(SimpleXMLElement $row, array $strings, array $dateStyles, DateTimeImmutable $epoch): array
    {
        $values = [];
        $dates = [];

        foreach ($row->c as $cell) {
            $column = rtrim((string) $cell['r'], '0123456789');
            $raw = (string) $cell->v;
            $type = (string) $cell['t'];
            $value = match ($type) {
                's' => $strings[(int) $raw] ?? '',
                'inlineStr' => $this->text($cell->is),
                'e' => '',
                default => $raw,
            };
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            if (($type === '' || $type === 'n') && is_numeric($value) && ($dateStyles[(int) $cell['s']] ?? false)) {
                $value = $epoch->modify(sprintf('%+d days', (int) $value))->format('Y-m-d');
                $dates[$column] = true;
            }

            $values[$column] = $value;
        }

        return [$values, $dates];
    }

    private function evaluation(array $cells, array $dates, string $sheet): ?array
    {
        if (! isset($cells['B'], $cells['C'], $cells['H'])) {
            return null;
        }

        $rating = $cells['E'] ?? null;
        $rate = $cells['F'] ?? null;
        $score = is_numeric($rating) ? $rating : (is_numeric($rate) ? $rate : null);
        $status = is_numeric($rating) || $rating === null ? $rate : $rating;

        return [
            'kind' => 'evaluation', 'period' => substr($sheet, 4), 'reference' => $cells['B'],
            'reference_is_date' => isset($dates['B']), 'caterer' => $cells['H'], 'activity' => $cells['C'],
            'status' => is_numeric($status) ? null : $status, 'score' => $score, 'order_row' => null,
            'po_amount' => null, 'partial_amount' => null, 'reported_balance' => null,
        ];
    }

    private function notice(array $cells, array $dates): ?array
    {
        if (! isset($cells['B']) && ! isset($cells['E'], $cells['G'])) {
            return null;
        }

        return [
            'kind' => 'ntc', 'period' => null, 'reference' => $cells['B'] ?? null,
            'reference_is_date' => isset($dates['B']), 'caterer' => $cells['E'] ?? null,
            'activity' => $cells['G'] ?? null, 'status' => $cells['R'] ?? null,
            'score' => $this->amount($cells['Q'] ?? null), 'order_row' => null,
            'po_amount' => null, 'partial_amount' => null, 'reported_balance' => null,
        ];
    }

    private function purchase(array $cells, array $dates, ?string $period, ?array &$order, int $row): ?array
    {
        $isOrder = isset($cells['C']) && (isset($cells['B']) || isset($cells['H']));
        $isBilling = $order !== null && ! isset($cells['B']) && ! isset($cells['C'])
            && (isset($cells['D']) || isset($cells['P']) || isset($cells['AA']));

        if (! $isOrder && ! $isBilling) {
            return null;
        }

        if ($isOrder) {
            $order = [
                'source_row' => $row, 'reference' => $cells['C'], 'reference_is_date' => isset($dates['C']),
                'caterer' => $cells['B'] ?? null, 'activity' => $cells['H'] ?? null,
            ];
        }

        return [
            'kind' => $isOrder ? 'order' : 'billing', 'period' => $period,
            'reference' => $order['reference'], 'reference_is_date' => $order['reference_is_date'],
            'caterer' => $order['caterer'], 'activity' => $order['activity'], 'status' => $cells['D'] ?? null,
            'order_row' => $isBilling ? $order['source_row'] : null, 'score' => null,
            'po_amount' => $isOrder ? $this->amount($cells['F'] ?? null) : null,
            'partial_amount' => $this->amount($cells['E'] ?? null),
            'reported_balance' => $this->amount($cells['G'] ?? null),
        ];
    }

    private function amount(?string $value): ?string
    {
        $value = str_replace([',', '₱', ' '], '', $value ?? '');

        return is_numeric($value) ? $value : null;
    }
}
