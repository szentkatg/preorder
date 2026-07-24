<?php

namespace App\Livewire\Partner;

use App\Exports\SalesRepSummaryExport;
use App\Models\Order;
use App\Services\Partner\SalesRepOrderSummaryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

class SalesRepOrderSummary extends Component
{
    protected ?Collection $accessibleOrdersCache = null;

    protected ?Collection $salesRepOrderSummariesCache = null;

    protected ?Collection $filteredSummariesCache = null;

    public array $selectedSummaryOrderIds = [];

    public string $summarySearch = '';

    public ?int $summarySeasonId = null;

    public ?int $summaryBrandId = null;

    public ?int $summaryOrderSheetTypeId = null;

    public ?string $summaryFilledFilter = null;

    public ?string $summaryCurrency = null;

    public bool $summaryLoaded = false;

    public function boot(): void
    {
        app()->setLocale((string) session('partner.locale', 'hu'));
    }

    public function mount(): void
    {
        $filters = session('order_selector.summary_filters', []);

        $this->summarySearch = $filters['search'] ?? '';
        $this->summarySeasonId = $filters['season_id'] ?? null;
        $this->summaryBrandId = $filters['brand_id'] ?? null;
        $this->summaryOrderSheetTypeId = $filters['order_sheet_type_id'] ?? null;
        $this->summaryCurrency = $filters['currency'] ?? null;
        $this->summaryFilledFilter = $filters['filled_filter'] ?? null;
    }

    public function updated(string $property): void
    {
        if (! str_starts_with($property, 'summary')) {
            return;
        }

        $this->selectedSummaryOrderIds = [];
        $this->filteredSummariesCache = null;

        session([
            'order_selector.summary_filters' => [
                'search' => $this->summarySearch,
                'season_id' => $this->summarySeasonId,
                'brand_id' => $this->summaryBrandId,
                'order_sheet_type_id' => $this->summaryOrderSheetTypeId,
                'currency' => $this->summaryCurrency,
                'filled_filter' => $this->summaryFilledFilter,
            ],
        ]);
    }

    public function updatedSelectedSummaryOrderIds(): void
    {
        $this->selectedSummaryOrderIds = collect($this->selectedSummaryOrderIds)
            ->map(fn ($id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function getAccessibleOrdersProperty(): Collection
    {
        if ($this->accessibleOrdersCache instanceof Collection) {
            return $this->accessibleOrdersCache;
        }

        $partnerUser = auth('partner')->user();

        if (! $partnerUser) {
            return $this->accessibleOrdersCache = collect();
        }

        return $this->accessibleOrdersCache = $partnerUser
            ->accessibleOrdersQuery()
            ->with([
                'season',
                'brand',
                'partner',
                'partnerAddress.language',
                'orderSheetType',
                'priceList.currency',
            ])
            ->orderBy('season_id')
            ->orderBy('brand_id')
            ->orderBy('partner_address_id')
            ->orderBy('order_sheet_type_id')
            ->get();
    }

    public function loadSummary(): void
    {
        $summaries = app(SalesRepOrderSummaryService::class)
            ->build($this->accessibleOrders)
            ->values()
            ->all();

        Cache::put($this->summaryCacheKey(), $summaries, now()->addMinutes(15));

        $this->salesRepOrderSummariesCache = collect($summaries);
        $this->filteredSummariesCache = null;
        $this->summaryLoaded = true;
    }

    #[On('order-summary-changed')]
    public function refreshSummary(): void
    {
        Cache::forget($this->summaryCacheKey());
        $this->accessibleOrdersCache = null;
        $this->salesRepOrderSummariesCache = null;
        $this->filteredSummariesCache = null;
        $this->loadSummary();
    }

    public function getSalesRepOrderSummariesProperty(): Collection
    {
        if ($this->salesRepOrderSummariesCache instanceof Collection) {
            return $this->salesRepOrderSummariesCache;
        }

        return $this->salesRepOrderSummariesCache = collect(
            Cache::get($this->summaryCacheKey(), [])
        );
    }

    public function getFilteredSalesRepOrderSummariesProperty(): Collection
    {
        if ($this->filteredSummariesCache instanceof Collection) {
            return $this->filteredSummariesCache;
        }

        return $this->filteredSummariesCache = $this->salesRepOrderSummaries
            ->when($this->summarySearch, function (Collection $summaries) {
                $search = mb_strtolower(trim($this->summarySearch));

                return $summaries->filter(function (array $summary) use ($search): bool {
                    $haystack = mb_strtolower(implode(' ', [
                        $summary['partner_code'] ?? '',
                        $summary['partner_name'] ?? '',
                        $summary['address_name'] ?? '',
                        $summary['address'] ?? '',
                    ]));

                    return str_contains($haystack, $search);
                });
            })
            ->when(
                $this->summarySeasonId,
                fn (Collection $rows) => $rows->where(
                    'season_id',
                    (int) $this->summarySeasonId
                )
            )
            ->when(
                $this->summaryBrandId,
                fn (Collection $rows) => $rows->where(
                    'brand_id',
                    (int) $this->summaryBrandId
                )
            )
            ->when(
                $this->summaryOrderSheetTypeId,
                fn (Collection $rows) => $rows->where(
                    'order_sheet_type_id',
                    (int) $this->summaryOrderSheetTypeId
                )
            )
            ->when(
                $this->summaryFilledFilter === 'filled',
                fn (Collection $rows) => $rows->filter(
                    fn (array $row): bool => (int) ($row['quantity'] ?? 0) > 0
                )
            )
            ->when(
                $this->summaryFilledFilter === 'empty',
                fn (Collection $rows) => $rows->filter(
                    fn (array $row): bool => (int) ($row['quantity'] ?? 0) === 0
                )
            )
            ->when(
                $this->summaryCurrency,
                fn (Collection $rows) => $rows->where(
                    'currency',
                    $this->summaryCurrency
                )
            )
            ->values();
    }

    public function getSummaryCurrenciesProperty(): Collection
    {
        return $this->salesRepOrderSummaries
            ->pluck('currency')
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    public function selectAllSummaryOrders(): void
    {
        $this->selectedSummaryOrderIds = $this->filteredSalesRepOrderSummaries
            ->pluck('order_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    public function clearSelectedSummaryOrders(): void
    {
        $this->selectedSummaryOrderIds = [];
    }

    public function openSummaryOrder(int $orderId): void
    {
        $order = $this->accessibleOrders->firstWhere('id', $orderId);

        abort_unless($order instanceof Order, 403);

        session([
            'partner_order_selector.season_id' => (int) $order->season_id,
            'partner_order_selector.brand_id' => (int) $order->brand_id,
            'partner_order_selector.partner_address_id' => (int) $order->partner_address_id,
            'partner_order_selector.order_sheet_type_id' => (int) $order->order_sheet_type_id,
            'partner_order_selector.order_id' => (int) $order->id,
            'order_selector.open_order_id' => (int) $order->id,
        ]);

        $this->redirectRoute('partner.orders.select', navigate: true);
    }

    public function openSelectedSummaryOrders()
    {
        $selectedOrderIds = collect($this->selectedSummaryOrderIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($selectedOrderIds->isEmpty()) {
            $this->addError(
                'selectedSummaryOrderIds',
                __('partner.select_at_least_one_order_for_summary')
            );

            return null;
        }

        $orders = $this->accessibleOrders
            ->whereIn('id', $selectedOrderIds)
            ->values();

        if ($orders->isEmpty()) {
            $this->addError(
                'selectedSummaryOrderIds',
                __('partner.selected_orders_not_available')
            );

            return null;
        }

        $combinationCount = $orders
            ->map(fn (Order $order): string => implode('-', [
                $order->season_id,
                $order->brand_id,
                $order->order_sheet_type_id,
            ]))
            ->unique()
            ->count();

        if ($combinationCount !== 1) {
            $this->addError(
                'selectedSummaryOrderIds',
                __('partner.only_same_summary_combination_allowed')
            );

            return null;
        }

        $firstOrder = $orders->first();

        return redirect()->route('partner.orders.summary', [
            'season' => $firstOrder->season_id,
            'brand' => $firstOrder->brand_id,
            'orderSheetType' => $firstOrder->order_sheet_type_id,
            'orders' => $selectedOrderIds->implode(','),
        ]);
    }

    public function exportSalesRepSummary()
    {
        return Excel::download(
            new SalesRepSummaryExport($this->filteredSalesRepOrderSummaries),
            __('partner.sales_rep_export_filename')
                . '-'
                . now()->format('Ymd-His')
                . '.xlsx'
        );
    }

    public function render()
    {
        $summaries = $this->summaryLoaded
            ? $this->salesRepOrderSummaries
            : collect();

        return view('livewire.partner.sales-rep-order-summary', [
            'summarySeasons' => $this->summaryOptions(
                $summaries,
                'season_id',
                'season'
            ),
            'summaryBrands' => $this->summaryOptions(
                $summaries,
                'brand_id',
                'brand'
            ),
            'summaryOrderSheetTypes' => $this->summaryOptions(
                $summaries,
                'order_sheet_type_id',
                'type'
            ),
        ]);
    }

    protected function summaryOptions(
        Collection $summaries,
        string $idKey,
        string $nameKey
    ): Collection {
        return $summaries
            ->map(fn (array $summary): array => [
                'id' => (int) ($summary[$idKey] ?? 0),
                'name' => (string) ($summary[$nameKey] ?? ''),
            ])
            ->filter(fn (array $option): bool => $option['id'] > 0)
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    protected function summaryCacheKey(): string
    {
        return 'order-selector:sales-rep-summary:' . (int) auth('partner')->id();
    }
}
