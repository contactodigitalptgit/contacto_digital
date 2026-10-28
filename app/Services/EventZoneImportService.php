<?php

namespace App\Services;

use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventZone;
use App\Models\EventZoneDay;
use App\Services\ZoneSoft\ZoneSoftStoreNameService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Lê um Excel/CSV com a distribuição dos TPAs pelas zonas.
 *
 * Aceita colunas "Store ID", "Zona", "Operador" e "Início" (opcional), ou uma
 * só coluna "Nome" no formato da ZoneSoft: "103 - Bar 1 - Ana Caldas".
 */
class EventZoneImportService
{
    private const MAX_ROWS = 1000;

    private const HEADER_ALIASES = [
        'store_id' => ['store id', 'storeid', 'id loja', 'loja', 'store', 'tpa'],
        'zone' => ['zona', 'zone', 'bar'],
        'operator' => ['operador', 'operator', 'funcionario', 'funcionária', 'funcionário'],
        'starts_at' => ['inicio', 'início', 'hora', 'a partir de', 'starts at', 'start'],
        'full_name' => ['nome', 'nome da loja', 'name', 'descricao', 'descrição'],
    ];

    /**
     * @return list<array{line: int, store_id: int|null, zone: string, operator: string|null, starts_at: string|null}>
     */
    public function parse(UploadedFile $file): array
    {
        try {
            $sheet = $this->loadSheet($file);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'Não foi possível ler o ficheiro. Use .xlsx, .xls ou .csv.']);
        }

        $rows = $sheet->toArray(null, true, false, false);
        $header = array_map(fn (mixed $value): string => $this->normalizeHeader($value), array_shift($rows) ?? []);
        $columns = $this->resolveColumns($header);

        if (! isset($columns['full_name']) && ! isset($columns['store_id'])) {
            throw ValidationException::withMessages([
                'file' => 'O ficheiro precisa das colunas "Store ID" e "Zona" (e opcionalmente "Operador" e "Início"), ou de uma coluna "Nome" como "103 - Bar 1 - Ana Caldas".',
            ]);
        }

        $parsed = [];

        foreach ($rows as $index => $row) {
            if (count(array_filter($row, fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }

            if (count($parsed) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => sprintf('O ficheiro tem mais de %d linhas.', self::MAX_ROWS)]);
            }

            $storeId = isset($columns['store_id']) ? $this->integerOrNull($row[$columns['store_id']] ?? null) : null;
            $zone = isset($columns['zone']) ? trim((string) ($row[$columns['zone']] ?? '')) : '';
            $operator = isset($columns['operator']) ? trim((string) ($row[$columns['operator']] ?? '')) : '';

            // A coluna pode trazer o nome completo da ZoneSoft ("103 - Bar 1 - Ana Caldas").
            $fullName = isset($columns['full_name']) ? (string) ($row[$columns['full_name']] ?? '') : '';
            if ($fullName === '' && $storeId === null && isset($columns['store_id'])) {
                $fullName = (string) ($row[$columns['store_id']] ?? '');
            }

            if (str_contains($fullName, ' - ') && ($storeId === null || $zone === '')) {
                [$storeId, $zone, $operator] = $this->splitFullName($fullName, $storeId);
            }

            $parsed[] = [
                'line' => $index + 2,
                'store_id' => $storeId,
                'zone' => $zone,
                'operator' => $operator !== '' ? $operator : null,
                'starts_at' => isset($columns['starts_at']) ? $this->cellToString($row[$columns['starts_at']] ?? null) : null,
            ];
        }

        if ($parsed === []) {
            throw ValidationException::withMessages(['file' => 'O ficheiro não tem linhas para importar.']);
        }

        return $parsed;
    }

    /**
     * Confere cada linha com os TPAs e as zonas do evento, sem gravar nada.
     *
     * @param  list<array{line?: int, store_id: int|null, zone: string, operator: string|null, starts_at: string|null}>  $rows
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function preview(Event $event, ?EventZoneDay $day, array $rows, CarbonImmutable $defaultStartsAt): array
    {
        $machines = $event->zonesoftMachines()->get()->keyBy(fn (ClientZoneSoftMachine $machine): int => (int) $machine->store_id);
        $zones = EventZone::query()
            ->where('event_id', $event->id)
            ->where('event_zone_day_id', $day?->id)
            ->whereNull('archived_at')
            ->get()
            ->keyBy(fn (EventZone $zone): string => mb_strtolower($zone->name));
        $seen = [];
        $result = [];

        foreach ($rows as $row) {
            $errors = [];
            $machine = $row['store_id'] !== null ? $machines->get($row['store_id']) : null;
            $zoneName = trim($row['zone']);
            $operator = $row['operator'] !== null ? trim($row['operator']) : null;
            $startsAt = $this->resolveStartsAt($row['starts_at'], $day, $defaultStartsAt);
            $name = ZoneSoftStoreNameService::nameFor($zoneName, $operator);

            if ($row['store_id'] === null) {
                $errors[] = 'Falta o Store ID.';
            } elseif (! $machine) {
                $errors[] = 'Este Store ID não está nos TPAs do evento.';
            } elseif (isset($seen[$row['store_id']])) {
                $errors[] = sprintf('Store ID repetido (linha %d).', $seen[$row['store_id']]);
            }

            if ($zoneName === '') {
                $errors[] = 'Falta a zona.';
            } elseif (mb_strlen($zoneName) > 120) {
                $errors[] = 'Nome da zona demasiado longo.';
            }

            if ($operator !== null && mb_strlen($operator) > 50) {
                $errors[] = 'Nome do operador com mais de 50 caracteres.';
            }

            if (mb_strlen($name) > ZoneSoftStoreNameService::MAX_NAME_LENGTH) {
                $errors[] = sprintf('"%s" passa de %d caracteres na ZoneSoft.', $name, ZoneSoftStoreNameService::MAX_NAME_LENGTH);
            }

            if (! $startsAt) {
                $errors[] = 'Hora de início inválida.';
            } elseif ($day && ($startsAt->lessThan($day->starts_at) || $startsAt->greaterThanOrEqualTo($day->ends_at))) {
                $errors[] = 'O início tem de estar dentro do dia operacional.';
            } elseif (! $this->withinEventPeriod($event, $startsAt)) {
                $errors[] = 'O início está fora do período do evento.';
            }

            if ($row['store_id'] !== null) {
                $seen[$row['store_id']] ??= $row['line'] ?? 0;
            }

            $result[] = [
                'line' => $row['line'] ?? null,
                'store_id' => $row['store_id'],
                'machine_id' => $machine?->id,
                'current_label' => $machine?->store_label,
                'zone' => $zoneName,
                'zone_exists' => $zones->has(mb_strtolower($zoneName)),
                'operator' => $operator,
                'starts_at' => $startsAt?->format('Y-m-d\TH:i'),
                'zonesoft_name' => $name,
                'errors' => $errors,
            ];
        }

        $valid = array_filter($result, fn (array $row): bool => $row['errors'] === []);

        return [
            'rows' => $result,
            'summary' => [
                'total' => count($result),
                'valid' => count($valid),
                'invalid' => count($result) - count($valid),
                'new_zones' => collect($valid)->reject(fn (array $row): bool => $row['zone_exists'])
                    ->unique(fn (array $row): string => mb_strtolower($row['zone']))->count(),
            ],
        ];
    }

    private function withinEventPeriod(Event $event, CarbonImmutable $at): bool
    {
        $startsAt = $event->report_starts_at ?? $event->event_date?->copy()->startOfDay();

        return (! $startsAt || $at->greaterThanOrEqualTo($startsAt))
            && (! $event->report_ends_at || $at->lessThanOrEqualTo($event->report_ends_at));
    }

    private function loadSheet(UploadedFile $file): Worksheet
    {
        $path = $file->getRealPath();
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['csv', 'txt'], true)) {
            return IOFactory::load($path)->getActiveSheet();
        }

        // O separador só pode ser vírgula, ponto e vírgula ou tab: os nomes
        // têm espaços ("Bar 1 - Ana Caldas").
        $firstLine = (string) strtok((string) file_get_contents($path, length: 4096), "\n");
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        $reader = new CsvReader;
        $reader->setDelimiter((string) array_key_first($counts));
        $reader->setInputEncoding(CsvReader::GUESS_ENCODING);

        return $reader->load($path)->getActiveSheet();
    }

    private function resolveStartsAt(?string $value, ?EventZoneDay $day, CarbonImmutable $default): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $default;
        }

        // Célula só com a hora: o Excel guarda-a como fração de um dia.
        if (is_numeric($value) && (float) $value >= 0 && (float) $value < 1) {
            $minutes = (int) round((float) $value * 1440);
            $value = sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
        } elseif (is_numeric($value)) {
            try {
                $value = SpreadsheetDate::excelToDateTimeObject((float) $value)->format('Y-m-d H:i:s');
            } catch (Throwable) {
                return null;
            }

            // Uma célula só com a hora vem como fração de dia (data 1899/1900).
            if (str_starts_with($value, '1899-') || str_starts_with($value, '1900-01-0')) {
                $value = substr($value, 11, 5);
            }
        }

        // Só a hora ("17:00"): usa a data do dia operacional; antes da hora de
        // abertura significa depois da meia-noite.
        if (preg_match('/^(\d{1,2})[:h](\d{2})/', $value, $time) && ! preg_match('/\d{4}|\d{1,2}[\/.-]\d{1,2}[\/.-]/', $value)) {
            $base = CarbonImmutable::instance($day?->starts_at ?? $default);
            $at = $base->setTime((int) $time[1], (int) $time[2]);

            return $day && $at->lessThan($day->starts_at) ? $at->addDay() : $at;
        }

        try {
            return str_contains($value, '/')
                ? CarbonImmutable::createFromFormat('d/m/Y H:i', $value) ?: null
                : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0: int|null, 1: string, 2: string} */
    private function splitFullName(string $value, ?int $storeId): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(' - ', $value)), fn (string $part): bool => $part !== ''));

        if ($parts !== [] && ctype_digit($parts[0])) {
            $storeId ??= (int) array_shift($parts);
        }

        if (count($parts) >= 2) {
            $operator = array_pop($parts);

            return [$storeId, implode(' - ', $parts), $operator];
        }

        return [$storeId, $parts[0] ?? '', ''];
    }

    /** @param list<string> $header */
    private function resolveColumns(array $header): array
    {
        $columns = [];

        foreach (self::HEADER_ALIASES as $key => $aliases) {
            foreach ($header as $index => $name) {
                if (in_array($name, array_map(fn (string $alias): string => $this->normalizeHeader($alias), $aliases), true)) {
                    $columns[$key] = $index;

                    break;
                }
            }
        }

        return $columns;
    }

    private function normalizeHeader(mixed $value): string
    {
        return Str::of((string) $value)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]/', ' ')->squish()->value();
    }

    private function integerOrNull(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    private function cellToString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
