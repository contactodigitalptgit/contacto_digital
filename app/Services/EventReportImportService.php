<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportRow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use Throwable;

class EventReportImportService
{
    /**
     * The current export layout keeps business data in these fixed columns.
     *
     * @var array<string, int>
     */
    private const COLUMN_MAP = [
        'store_code' => 1,
        'store_name' => 2,
        'sale_date' => 4,
        'sale_datetime' => 5,
        'doc_type' => 7,
        'document_series' => 8,
        'document_number' => 10,
        'value' => 11,
        'total' => 12,
        'discount' => 13,
        'quantity' => 15,
        'product_code' => 16,
        'description' => 18,
    ];

    /**
     * @var list<string>
     */
    private const EXPECTED_HEADERS = [
        'loja',
        'nome_loja',
        'data',
        'datahora',
        'doc',
        'serie',
        'numero',
        'valor',
        'total',
        'desconto',
        'qtd',
        'codigo',
        'descricao',
    ];

    public function import(
        Event $event,
        UploadedFile $file,
        string $strategy,
        ?User $uploadedBy = null,
    ): EventReportImport {
        $storedPath = $file->store("event-reports/{$event->id}", 'local');
        $absolutePath = Storage::disk('local')->path($storedPath);

        try {
            [$rows, $summary, $headers] = $this->parseWorkbook($absolutePath);
        } catch (ValidationException $exception) {
            Storage::disk('local')->delete($storedPath);

            throw $exception;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPath);

            throw ValidationException::withMessages([
                'report_file' => 'Nao foi possivel ler a planilha. Verifique se o arquivo segue o layout esperado do evento.',
            ]);
        }

        return DB::transaction(function () use ($event, $file, $strategy, $uploadedBy, $storedPath, $rows, $summary, $headers): EventReportImport {
            $timestamp = now();

            if ($strategy === 'replace') {
                $event->reportImports()
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }

            $import = $event->reportImports()->create([
                'uploaded_by_user_id' => $uploadedBy?->id,
                'import_strategy' => $strategy,
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'mime_type' => $file->getClientMimeType(),
                'file_hash' => hash_file('sha256', Storage::disk('local')->path($storedPath)),
                'headers' => $headers,
                'summary' => $summary,
                'imported_rows_count' => count($rows),
                'imported_at' => $timestamp,
                'is_active' => true,
                'status' => 'completed',
            ]);

            foreach (array_chunk($rows, 500) as $chunk) {
                EventReportRow::query()->insert(
                    array_map(
                        fn (array $row): array => [
                            ...$row,
                            'event_id' => $event->id,
                            'event_report_import_id' => $import->id,
                            'raw_row' => json_encode($row['raw_row'], JSON_UNESCAPED_UNICODE),
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ],
                        $chunk,
                    ),
                );
            }

            return $import->fresh();
        });
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function parseWorkbook(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($path);
        $rows = [];
        $headers = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            foreach ($worksheet->toArray(null, true, true, false) as $index => $rawRow) {
                $normalizedRow = array_map(
                    fn ($value): string => $this->normalizeCell($value),
                    $rawRow,
                );

                if ($this->isEmptyRow($normalizedRow)) {
                    continue;
                }

                if ($this->isHeaderRow($normalizedRow)) {
                    $headers[] = [
                        'sheet' => $worksheet->getTitle(),
                        'row_number' => $index + 1,
                        'columns' => $normalizedRow,
                    ];

                    continue;
                }

                $mappedRow = $this->mapRow($normalizedRow, $worksheet->getTitle(), $index + 1);

                if (! $this->isMeaningfulDataRow($mappedRow)) {
                    continue;
                }

                $rows[] = $mappedRow;
            }
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'report_file' => 'Nenhuma linha valida foi encontrada na planilha enviada.',
            ]);
        }

        return [$rows, $this->buildSummary($rows), $headers];
    }

    /**
     * @param  array<int, string>  $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row, string $sheetName, int $rowNumber): array
    {
        return [
            'source_sheet' => $sheetName,
            'source_row_number' => $rowNumber,
            'store_code' => $this->valueAt($row, self::COLUMN_MAP['store_code']),
            'store_name' => $this->valueAt($row, self::COLUMN_MAP['store_name']),
            'sale_date' => $this->parseDate($this->valueAt($row, self::COLUMN_MAP['sale_date'])),
            'sale_datetime' => $this->parseDateTime($this->valueAt($row, self::COLUMN_MAP['sale_datetime'])),
            'doc_type' => $this->valueAt($row, self::COLUMN_MAP['doc_type']),
            'document_series' => $this->valueAt($row, self::COLUMN_MAP['document_series']),
            'document_number' => $this->valueAt($row, self::COLUMN_MAP['document_number']),
            'value' => $this->parseDecimal($this->valueAt($row, self::COLUMN_MAP['value'])),
            'total' => $this->parseDecimal($this->valueAt($row, self::COLUMN_MAP['total'])),
            'discount' => $this->parseDecimal($this->valueAt($row, self::COLUMN_MAP['discount'])),
            'quantity' => $this->parseDecimal($this->valueAt($row, self::COLUMN_MAP['quantity'])),
            'product_code' => $this->valueAt($row, self::COLUMN_MAP['product_code']),
            'description' => $this->valueAt($row, self::COLUMN_MAP['description']),
            'raw_row' => $row,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function buildSummary(array $rows): array
    {
        $totals = [
            'value' => 0.0,
            'total' => 0.0,
            'discount' => 0.0,
            'quantity' => 0.0,
        ];

        foreach ($rows as $row) {
            $totals['value'] += (float) ($row['value'] ?? 0);
            $totals['total'] += (float) ($row['total'] ?? 0);
            $totals['discount'] += (float) ($row['discount'] ?? 0);
            $totals['quantity'] += (float) ($row['quantity'] ?? 0);
        }

        return [
            'rows_count' => count($rows),
            'unique_stores' => count(array_unique(array_values(array_filter(array_column($rows, 'store_name'))))),
            'unique_products' => count(array_unique(array_values(array_filter(array_column($rows, 'product_code'))))),
            'value_total' => number_format($totals['value'], 4, '.', ''),
            'sales_total' => number_format($totals['total'], 4, '.', ''),
            'discount_total' => number_format($totals['discount'], 4, '.', ''),
            'quantity_total' => number_format($totals['quantity'], 4, '.', ''),
        ];
    }

    /**
     * @param  array<int, string>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        return collect($row)->every(fn (string $value): bool => $value === '');
    }

    /**
     * @param  array<int, string>  $row
     */
    private function isHeaderRow(array $row): bool
    {
        $normalizedValues = array_values(array_filter(array_map(
            fn (string $value): string => $this->normalizeHeaderValue($value),
            $row,
        )));

        return count(array_intersect($normalizedValues, self::EXPECTED_HEADERS)) >= 5;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isMeaningfulDataRow(array $row): bool
    {
        return collect([
            $row['store_code'] ?? null,
            $row['store_name'] ?? null,
            $row['document_number'] ?? null,
            $row['product_code'] ?? null,
            $row['description'] ?? null,
            $row['total'] ?? null,
        ])->contains(fn ($value): bool => $value !== null && $value !== '');
    }

    private function normalizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function normalizeHeaderValue(string $value): string
    {
        return (string) Str::of(Str::ascii($value))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_');
    }

    /**
     * @param  array<int, string>  $row
     */
    private function valueAt(array $row, int $index): ?string
    {
        $value = $row[$index] ?? '';

        return $value === '' ? null : $value;
    }

    private function parseDecimal(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return number_format((float) $value, 4, '.', '');
        }

        $normalized = str_replace('.', '', $value);
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? '';

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 4, '.', '');
    }

    private function parseDate(?string $value): ?string
    {
        $carbon = $this->parseSpreadsheetDate($value);

        return $carbon?->toDateString();
    }

    private function parseDateTime(?string $value): ?string
    {
        $carbon = $this->parseSpreadsheetDate($value);

        return $carbon?->format('Y-m-d H:i:s');
    }

    private function parseSpreadsheetDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return CarbonImmutable::instance(
                SpreadsheetDate::excelToDateTimeObject((float) $value),
            );
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
