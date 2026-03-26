<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventReportRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EventDashboardController extends Controller
{
    public function show(Request $request, Event $event): Response
    {
        $client = $request->user()->client()->firstOrFail();

        abort_unless(
            $event->client_id === $client->id && $event->is_active,
            404,
        );

        return $this->renderDashboard(
            $request,
            $event,
            false,
            route('dashboard'),
            'Voltar ao dashboard',
        );
    }

    public function preview(Request $request, Event $event): Response
    {
        return $this->renderDashboard(
            $request,
            $event,
            true,
            route('admin.events.index'),
            'Voltar para eventos',
        );
    }

    private function renderDashboard(
        Request $request,
        Event $event,
        bool $previewMode,
        string $backUrl,
        string $backLabel,
    ): Response {
        $event->load(['client', 'latestActiveReportImport'])
            ->loadCount('activeReportImports');

        $filters = $this->normalizeFilters($request);

        $baseRowsQuery = EventReportRow::query()
            ->where('event_id', $event->id)
            ->fromActiveImports();

        $filteredRowsQuery = $this->applyFilters(clone $baseRowsQuery, $filters);

        $rows = (clone $filteredRowsQuery)
            ->orderByDesc('sale_datetime')
            ->orderByDesc('sale_date')
            ->orderByDesc('source_row_number')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Events/Dashboard', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'event_date' => $event->event_date->toISOString(),
                'client_name' => $event->client->name,
                'client_business_name' => $event->client->business_name,
                'active_imports_count' => (int) $event->active_report_imports_count,
                'last_imported_at' => $event->latestActiveReportImport?->imported_at?->toISOString(),
                'last_filename' => $event->latestActiveReportImport?->original_filename,
            ],
            'filters' => $filters,
            'filterOptions' => [
                'barGroups' => $this->buildBarGroupOptions(clone $baseRowsQuery),
                'stores' => $this->buildStoreOptions(clone $baseRowsQuery),
                'products' => $this->buildProductOptions(clone $baseRowsQuery),
            ],
            'summary' => $this->buildSummary(
                clone $baseRowsQuery,
                clone $filteredRowsQuery,
                (int) $event->active_report_imports_count,
                $event->latestActiveReportImport?->imported_at?->toISOString(),
                $event->latestActiveReportImport?->original_filename,
            ),
            'barGroups' => $this->buildBarGroups(clone $filteredRowsQuery),
            'topStores' => $this->buildTopStores(clone $filteredRowsQuery),
            'topProducts' => $this->buildTopProducts(clone $filteredRowsQuery),
            'rows' => $rows->getCollection()->map(fn (EventReportRow $row): array => [
                'id' => $row->id,
                'store_code' => $row->store_code,
                'store_name' => $row->store_name,
                'sale_date' => $row->sale_date?->toDateString(),
                'sale_datetime' => $row->sale_datetime?->toISOString(),
                'doc_type' => $row->doc_type,
                'document_series' => $row->document_series,
                'document_number' => $row->document_number,
                'product_code' => $row->product_code,
                'description' => $row->description,
                'quantity' => (float) ($row->quantity ?? 0),
                'value' => (float) ($row->value ?? 0),
                'discount' => (float) ($row->discount ?? 0),
                'total' => (float) ($row->total ?? 0),
            ])->values(),
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
                'prev_page_url' => $rows->previousPageUrl(),
                'next_page_url' => $rows->nextPageUrl(),
            ],
            'previewMode' => $previewMode,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }

    /**
     * @return array{bar_group: string, store: string, product: string, date_from: string, date_to: string, total_min: string, total_max: string}
     */
    private function normalizeFilters(Request $request): array
    {
        $validated = $request->validate([
            'bar_group' => ['nullable', 'string', 'max:255'],
            'store' => ['nullable', 'string', 'max:255'],
            'product' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'total_min' => ['nullable', 'string', 'max:40'],
            'total_max' => ['nullable', 'string', 'max:40'],
        ]);

        return [
            'bar_group' => trim((string) ($validated['bar_group'] ?? '')),
            'store' => trim((string) ($validated['store'] ?? '')),
            'product' => trim((string) ($validated['product'] ?? '')),
            'date_from' => trim((string) ($validated['date_from'] ?? '')),
            'date_to' => trim((string) ($validated['date_to'] ?? '')),
            'total_min' => $this->normalizeDecimalString($validated['total_min'] ?? null),
            'total_max' => $this->normalizeDecimalString($validated['total_max'] ?? null),
        ];
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if ($filters['bar_group'] !== '') {
            $this->applyBarGroupFilter($query, $filters['bar_group']);
        }

        if ($filters['store'] !== '') {
            $query->where('store_name', $filters['store']);
        }

        if ($filters['product'] !== '') {
            $query->where('product_code', $filters['product']);
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('sale_date', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate('sale_date', '<=', $filters['date_to']);
        }

        if ($filters['total_min'] !== '') {
            $query->where('total', '>=', (float) $filters['total_min']);
        }

        if ($filters['total_max'] !== '') {
            $query->where('total', '<=', (float) $filters['total_max']);
        }

        return $query;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBarGroupOptions(Builder $query): array
    {
        return collect($this->buildBarGroups($query))
            ->map(fn (array $group): array => [
                'value' => (string) $group['label'],
                'label' => (string) $group['label'],
                'rows_count' => (int) $group['rows_count'],
            ])
            ->sortBy(fn (array $group): string => Str::lower($group['label']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildStoreOptions(Builder $query): array
    {
        return $query
            ->select('store_name')
            ->selectRaw('COUNT(*) as rows_count')
            ->whereNotNull('store_name')
            ->where('store_name', '!=', '')
            ->groupBy('store_name')
            ->orderBy('store_name')
            ->get()
            ->map(fn (EventReportRow $row): array => [
                'value' => (string) $row->store_name,
                'label' => (string) $row->store_name,
                'rows_count' => (int) $row->rows_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildProductOptions(Builder $query): array
    {
        return $query
            ->select('product_code', 'description')
            ->selectRaw('COUNT(*) as rows_count')
            ->whereNotNull('product_code')
            ->where('product_code', '!=', '')
            ->groupBy('product_code', 'description')
            ->orderBy('description')
            ->orderBy('product_code')
            ->get()
            ->map(fn (EventReportRow $row): array => [
                'value' => (string) $row->product_code,
                'label' => trim(sprintf(
                    '%s%s',
                    (string) ($row->description ?: 'Produto sem descricao'),
                    $row->product_code ? " ({$row->product_code})" : '',
                )),
                'rows_count' => (int) $row->rows_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(
        Builder $baseRowsQuery,
        Builder $filteredRowsQuery,
        int $activeImportsCount,
        ?string $lastImportedAt,
        ?string $lastFilename,
    ): array {
        $filteredRowsCount = (clone $filteredRowsQuery)->count();
        $totalSales = (float) ((clone $filteredRowsQuery)->sum('total') ?? 0);

        return [
            'active_imports_count' => $activeImportsCount,
            'total_rows' => (int) ((clone $baseRowsQuery)->count()),
            'filtered_rows' => $filteredRowsCount,
            'bar_groups_count' => count($this->buildBarGroups(clone $filteredRowsQuery)),
            'total_sales' => $totalSales,
            'total_value' => (float) ((clone $filteredRowsQuery)->sum('value') ?? 0),
            'total_discount' => (float) ((clone $filteredRowsQuery)->sum('discount') ?? 0),
            'total_quantity' => (float) ((clone $filteredRowsQuery)->sum('quantity') ?? 0),
            'stores_count' => (int) ((clone $filteredRowsQuery)
                ->whereNotNull('store_name')
                ->where('store_name', '!=', '')
                ->distinct()
                ->count('store_name')),
            'products_count' => (int) ((clone $filteredRowsQuery)
                ->whereNotNull('product_code')
                ->where('product_code', '!=', '')
                ->distinct()
                ->count('product_code')),
            'average_ticket' => $filteredRowsCount > 0
                ? round($totalSales / $filteredRowsCount, 4)
                : 0,
            'last_imported_at' => $lastImportedAt,
            'last_filename' => $lastFilename,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBarGroups(Builder $query): array
    {
        /** @var \Illuminate\Support\Collection<int, EventReportRow> $rows */
        $rows = $query
            ->get(['store_name', 'store_code', 'quantity', 'total']);

        return $rows
            ->groupBy(fn (EventReportRow $row): string => $this->resolveBarGroupLabel($row->store_name))
            ->map(function ($groupRows, string $label): array {
                $members = $groupRows
                    ->pluck('store_name')
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                return [
                    'label' => $label,
                    'stores_count' => count($members),
                    'members' => $members,
                    'rows_count' => $groupRows->count(),
                    'quantity_total' => round((float) $groupRows->sum('quantity'), 4),
                    'sales_total' => round((float) $groupRows->sum('total'), 4),
                ];
            })
            ->sortByDesc('sales_total')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTopStores(Builder $query): array
    {
        return $query
            ->select('store_name', 'store_code')
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity_total')
            ->selectRaw('COALESCE(SUM(total), 0) as sales_total')
            ->groupBy('store_name', 'store_code')
            ->orderByDesc('sales_total')
            ->limit(5)
            ->get()
            ->map(fn (EventReportRow $row): array => [
                'label' => $row->store_name ?: 'Sem loja',
                'code' => $row->store_code,
                'rows_count' => (int) $row->rows_count,
                'quantity_total' => (float) ($row->quantity_total ?? 0),
                'sales_total' => (float) ($row->sales_total ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTopProducts(Builder $query): array
    {
        return $query
            ->select('product_code', 'description')
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity_total')
            ->selectRaw('COALESCE(SUM(total), 0) as sales_total')
            ->groupBy('product_code', 'description')
            ->orderByDesc('sales_total')
            ->limit(5)
            ->get()
            ->map(fn (EventReportRow $row): array => [
                'label' => $row->description ?: 'Produto sem descricao',
                'code' => $row->product_code,
                'rows_count' => (int) $row->rows_count,
                'quantity_total' => (float) ($row->quantity_total ?? 0),
                'sales_total' => (float) ($row->sales_total ?? 0),
            ])
            ->values()
            ->all();
    }

    private function normalizeDecimalString(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        $normalized = str_replace(' ', '', trim($value));

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? '';

        if ($normalized === '' || ! is_numeric($normalized)) {
            return '';
        }

        return (string) $normalized;
    }

    private function applyBarGroupFilter(Builder $query, string $barGroup): void
    {
        $normalizedBarGroup = trim($barGroup);

        if ($normalizedBarGroup === 'Sem loja') {
            $query->where(function (Builder $builder): void {
                $builder
                    ->whereNull('store_name')
                    ->orWhereRaw("TRIM(COALESCE(store_name, '')) = ''");
            });

            return;
        }

        if (preg_match('/^bar\s+\d+$/i', $normalizedBarGroup) === 1) {
            $normalizedLower = Str::lower($normalizedBarGroup);

            $query->where(function (Builder $builder) use ($normalizedLower): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(store_name, \'\')) = ?', [$normalizedLower])
                    ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', [$normalizedLower.' %'])
                    ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', [$normalizedLower.'-%']);
            });

            return;
        }

        $query->whereRaw('TRIM(COALESCE(store_name, \'\')) = ?', [$normalizedBarGroup]);
    }

    private function resolveBarGroupLabel(?string $storeName): string
    {
        if ($storeName === null || trim($storeName) === '') {
            return 'Sem loja';
        }

        if (preg_match('/^(bar\s+\d+)/i', $storeName, $matches) === 1) {
            return Str::title($matches[1]);
        }

        return trim($storeName);
    }
}
