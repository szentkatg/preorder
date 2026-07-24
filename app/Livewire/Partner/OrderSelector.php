<?php

namespace App\Livewire\Partner;

use App\Exports\SalesRepSummaryExport;
use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSheetType;
use App\Models\PartnerAddress;
use App\Models\PriceListItem;
use App\Models\PartnerUser;
use App\Models\Product;
use App\Models\Season;
use Illuminate\Support\Collection;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\PartnerOrderMatrixExport;
use Illuminate\Support\Facades\Mail;
use App\Livewire\Partner\Concerns\SetsPartnerLocale;
use App\Services\Partner\SalesRepOrderSummaryService;
use Illuminate\Support\Facades\Log;


class OrderSelector extends Component
{
    use SetsPartnerLocale;

    protected ?Collection $accessibleOrdersCache = null;

    protected ?Collection $salesRepOrderSummariesCache = null;

    protected ?Collection $filteredSalesRepOrderSummariesCache = null;

    protected ?Collection $summaryCurrenciesCache = null;

    protected float $requestStartedAt = 0.0;
    
    public ?int $seasonId = null;

    public ?int $brandId = null;

    public ?int $partnerAddressId = null;

    public ?int $orderSheetTypeId = null;

    public ?int $selectedOrderId = null;
    
    public string $modelSearch = '';

    public array $modelSearchResults = [];
    
    public int $modelSearchIndex = -1;

    public array $selectedSummaryOrderIds = [];

    public string $summarySearch = '';

    public string $addressSearch = '';

    public ?int $summarySeasonId = null;

    public ?int $summaryBrandId = null;

    public ?int $summaryOrderSheetTypeId = null;
    
    public ?string $summaryFilledFilter = null;

    public ?string $summaryCurrency = null;

    public bool $summaryLoaded = false;

    public ?array $salesRepOrderSummariesData = null;

    public function boot(): void
    {
        $this->requestStartedAt = microtime(true);
    }

    public function dehydrate(): void
    {
        if ($this->requestStartedAt <= 0) {
            return;
        }

        Log::info('OrderSelector performance: teljes Livewire kérés', [
            'duration_ms' => round(
                (microtime(true) - $this->requestStartedAt) * 1000,
                2
            ),
            'partner_user_id' => auth('partner')->id(),
            'summary_search' => $this->summarySearch,
            'summary_season_id' => $this->summarySeasonId,
            'summary_brand_id' => $this->summaryBrandId,
            'summary_order_sheet_type_id' =>
                $this->summaryOrderSheetTypeId,
            'summary_filled_filter' => $this->summaryFilledFilter,
            'summary_currency' => $this->summaryCurrency,
        ]);
    }

    protected function saveSummaryFilters(): void
    {
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

    protected function clearSummaryCaches(): void
    {
        $this->accessibleOrdersCache = null;
        $this->salesRepOrderSummariesCache = null;
        $this->filteredSalesRepOrderSummariesCache = null;
        $this->summaryCurrenciesCache = null;
        $this->salesRepOrderSummariesData = null;
        $this->summaryLoaded = false;
    }

    protected function restoreSummaryFilters(): void
    {
        $filters = session('order_selector.summary_filters');
    
        if (! $filters) {
            return;
        }
    
        $this->summarySearch = $filters['search'] ?? '';
        $this->summarySeasonId = $filters['season_id'] ?? null;
        $this->summaryBrandId = $filters['brand_id'] ?? null;
        $this->summaryOrderSheetTypeId = $filters['order_sheet_type_id'] ?? null;
        $this->summaryCurrency = $filters['currency'] ?? null;
        $this->summaryFilledFilter = $filters['filled_filter'] ?? null;
    }

    public function updated($property): void
    {
        if (str_starts_with($property, 'summary')) {
            $this->saveSummaryFilters();
        }
    }

    public function getSummaryCurrenciesProperty(): Collection
    {
        if ($this->summaryCurrenciesCache instanceof Collection) {
            return $this->summaryCurrenciesCache;
        }

        return $this->summaryCurrenciesCache =
            $this->salesRepOrderSummaries
                ->pluck('currency')
                ->filter()
                ->unique()
                ->sort()
                ->values();
    }
        
        public function mount(): void
        {
            $this->seasonId = session('partner_order_selector.season_id');
            $this->brandId = session('partner_order_selector.brand_id');
            $this->partnerAddressId = session('partner_order_selector.partner_address_id');
            $this->orderSheetTypeId = session('partner_order_selector.order_sheet_type_id');
            $this->selectedOrderId = session('partner_order_selector.order_id');
        
            $this->clearInvalidSessionSelection();
            $this->restoreSummaryFilters();
        
            $selectedOrderId = session()->pull('order_selector.open_order_id');
        
            if ($selectedOrderId) {
                $this->selectedOrderId = (int) $selectedOrderId;
        
                $this->setPartnerLocale((int) $this->partnerAddressId);
        
                $this->openOrder();
        
                return;
            }
        
            $this->setPartnerLocale(
                $this->partnerAddressId ? (int) $this->partnerAddressId : null
            );
        }

    public function getAccessibleOrdersProperty(): Collection
    {
        if ($this->accessibleOrdersCache instanceof Collection) {
            return $this->accessibleOrdersCache;
        }

        $startedAt = microtime(true);

        $partnerUser = auth('partner')->user();

        if (! $partnerUser) {
            return $this->accessibleOrdersCache = collect();
        }

        $orders = $partnerUser
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

        Log::info('OrderSelector performance: accessible orders', [
            'duration_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
            'order_count' => $orders->count(),
            'partner_user_id' => $partnerUser->id,
        ]);

        return $this->accessibleOrdersCache = $orders;
    }

    public function getSeasonsProperty(): Collection
    {
        return Season::query()
            ->orderBy('name')
            ->get();
    }

    public function getAddressesProperty(): Collection
    {
        $partnerUser = auth('partner')->user();

        if (! $partnerUser) {
            return collect();
        }

        if (! in_array($partnerUser->role, [
            PartnerUser::ROLE_PARTNER_ADMIN,
            PartnerUser::ROLE_ADDRESS_USER,
            PartnerUser::ROLE_SALES_REP,
        ], true)) {
            return collect();
        }

        $search = trim($this->addressSearch);

        return $this->accessibleAddressesQuery($partnerUser)
            ->join('partners', 'partners.id', '=', 'partner_addresses.partner_id')
            ->select('partner_addresses.*')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%' . $search . '%';

                $query->where(function ($query) use ($like) {
                    $query
                        ->where('partners.name', 'like', $like)
                        ->orWhere('partners.erp_partner_code', 'like', $like)
                        ->orWhere('partner_addresses.addrid', 'like', $like)
                        ->orWhere('partner_addresses.name', 'like', $like)
                        ->orWhere('partner_addresses.zip', 'like', $like)
                        ->orWhere('partner_addresses.city', 'like', $like)
                        ->orWhere('partner_addresses.street', 'like', $like);
                });
            })
            ->orderBy('partners.name')
            ->orderBy('partner_addresses.addrid')
            ->orderBy('partner_addresses.name')
            ->get()
            ->map(fn (PartnerAddress $address) => [
                'id' => (int) $address->id,
                'partner_id' => (int) $address->partner_id,
                'partner_name' => $address->partner?->name ?? '',
                'partner_code' => $address->partner?->erp_partner_code ?? '',
                'address_code' => $address->addrid ?? '',
                'address_name' => $address->name ?? $address->addrid ?? '',
                'address' => $this->formatAddress($address),
                'label' => trim(collect([
                    $address->partner?->name,
                    $address->partner?->erp_partner_code,
                    $address->addrid,
                    $address->name,
                    $this->formatAddress($address),
                ])->filter()->implode(' - ')),
            ])
            ->values();
    }

    protected function accessibleAddressesQuery(PartnerUser $partnerUser)
    {
        $query = PartnerAddress::query()
            ->with('partner')
            ->where('partner_addresses.active', true);

        if ($partnerUser->role === PartnerUser::ROLE_PARTNER_ADMIN) {
            return $query->where('partner_addresses.partner_id', $partnerUser->partner_id);
        }

        if ($partnerUser->role === PartnerUser::ROLE_ADDRESS_USER) {
            return $query->whereIn(
                'partner_addresses.id',
                $partnerUser->addresses()->select('partner_addresses.id')
            );
        }

        if ($partnerUser->role === PartnerUser::ROLE_SALES_REP) {
            return $query->where(function ($query) use ($partnerUser) {
                $query
                    ->whereIn('partner_addresses.partner_id', $partnerUser->partners()->select('partners.id'))
                    ->orWhereIn('partner_addresses.id', $partnerUser->addresses()->select('partner_addresses.id'));
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public function getBrandsProperty(): Collection
    {
        if (! $this->seasonId || ! $this->partnerAddressId) {
            return collect();
        }

        return Brand::query()
            ->join(
                'partner_address_brand',
                'partner_address_brand.brand_id',
                '=',
                'brands.id'
            )
            ->where('partner_address_brand.partner_address_id', $this->partnerAddressId)
            ->where('brands.active', true)
            ->whereExists(function ($query) {
                $query
                    ->selectRaw('1')
                    ->from('products')
                    ->whereColumn('products.brand_id', 'brands.id')
                    ->where('products.season_id', $this->seasonId)
                    ->where('products.active', true);
            })
            ->select('brands.*')
            ->orderBy('name')
            ->get();
    }

    public function updatedModelSearch(): void
    {
        $this->modelSearchResults = [];
        $this->modelSearchIndex = -1;
    }

    public function jumpToNextModelSearchResult(): void
    {
        $search = trim(mb_strtolower($this->modelSearch));
    
        if ($search === '') {
            $this->modelSearchResults = [];
            $this->modelSearchIndex = -1;
    
            return;
        }
    
        if (empty($this->modelSearchResults)) {
            $this->modelSearchResults = collect($this->catalogGroups)
                ->flatMap(function ($catalogGroup) use ($search) {
                    return collect($catalogGroup['matrix_groups'] ?? [])
                        ->flatMap(function ($matrixGroup) use ($catalogGroup, $search) {
                            return collect($matrixGroup['products'] ?? [])
                                ->filter(function ($product) use ($search) {
                                    $haystack = mb_strtolower(
                                        ($product['model_code'] ?? '') . ' ' .
                                        ($product['name'] ?? '') . ' ' .
                                        ($product['name_hu'] ?? '') . ' ' .
                                        ($product['name_en'] ?? '')
                                    );
    
                                    return str_contains($haystack, $search);
                                })
                                ->map(function ($product) use ($catalogGroup) {
                                    return [
                                        'catalog_group_name' => $catalogGroup['name'],
                                        'model_code' => $product['model_code'] ?? null,
                                    ];
                                });
                        });
                })
                ->values()
                ->all();
        }
    
        if (empty($this->modelSearchResults)) {
            return;
        }
    
        $this->modelSearchIndex++;
    
        if ($this->modelSearchIndex >= count($this->modelSearchResults)) {
            $this->modelSearchIndex = 0;
        }
    
        $result = $this->modelSearchResults[$this->modelSearchIndex];
    
        $this->navigateToCatalogGroup($result['catalog_group_name']);
    
        $this->dispatch('scroll-to-model', modelCode: $result['model_code']);
    }

    public function getOrderSheetTypesProperty(): Collection
    {
        if (! $this->seasonId || ! $this->partnerAddressId || ! $this->brandId) {
            return collect();
        }

        return OrderSheetType::query()
            ->join(
                'partner_address_order_sheet_type',
                'partner_address_order_sheet_type.order_sheet_type_id',
                '=',
                'order_sheet_types.id'
            )
            ->where(
                'partner_address_order_sheet_type.partner_address_id',
                $this->partnerAddressId
            )
            ->where('order_sheet_types.active', true)
            ->whereExists(function ($query) {
                $query
                    ->selectRaw('1')
                    ->from('products')
                    ->whereColumn('products.order_sheet_type_id', 'order_sheet_types.id')
                    ->where('products.season_id', $this->seasonId)
                    ->where('products.brand_id', $this->brandId)
                    ->where('products.active', true);
            })
            ->select('order_sheet_types.*')
            ->orderBy(app()->getLocale() === 'en' ? 'name_en' : 'name_hu')
            ->get();
    }

    public function getSelectedOrderProperty(): ?Order
    {
        return $this->selectedOrder();
    }

    protected function selectedOrder(): ?Order
    {
        if ($this->selectedOrderId) {
            $order = $this->accessibleOrders
                ->first(fn (Order $order): bool => (int) $order->id === (int) $this->selectedOrderId);
    
            if ($order instanceof Order) {
                return $order;
            }
        }
    
        return $this->findExistingOrder();
    }

    public function formatAddress($address): string
    {
        if (! $address) {
            return '';
        }

        return trim(collect([
            $address->country_code ?? null,
            trim(collect([
                $address->zip ?? $address->postal_code ?? null,
                $address->city ?? null,
            ])->filter()->implode(' ')),
            $address->street ?? $address->address ?? null,
        ])->filter()->implode(' - '));
    }

    public function updatedSeasonId(): void
    {
        $this->selectedOrderId = null;
        $this->brandId = null;
        $this->orderSheetTypeId = null;

        $this->storeSelection();
    }

    public function updatedPartnerAddressId(): void
    {
        $this->selectedOrderId = null;
        $this->brandId = null;
        $this->orderSheetTypeId = null;
        $this->setPartnerLocale(
                        $this->partnerAddressId ? (int) $this->partnerAddressId : null
                    );
        $this->storeSelection();
    }


    public function updatedAddressSearch(): void
    {
        // A kereső csak a címlistát szűri, a már kiválasztott címet nem töröljük.
    }

    public function updatedBrandId(): void
    {
        $this->selectedOrderId = null;
        $this->orderSheetTypeId = null;

        $this->storeSelection();
    }

    public function updatedOrderSheetTypeId(): void
    {
        $order = $this->findExistingOrder();

        $this->selectedOrderId = $order?->id;
    
        $this->storeSelection();
    }

    public function loadSummary(): void
    {
        $this->salesRepOrderSummariesData = app(
            SalesRepOrderSummaryService::class
        )->build($this->accessibleOrders)->all();
        $this->summaryLoaded = true;
    }

    protected function createOrder(): Order
    {
        $address = PartnerAddress::query()
            ->with(['partner', 'priceList'])
            ->findOrFail($this->partnerAddressId);

        abort_unless($this->canUseAddress($address), 403);

        $currencyId = $address->currency_id ?? $address->priceList?->currency_id;

        return Order::create([
            'season_id' => $this->seasonId,
            'partner_id' => $address->partner_id,
            'partner_address_id' => $address->id,
            'brand_id' => $this->brandId,
            'order_sheet_type_id' => $this->orderSheetTypeId,
            'price_list_id' => $address->price_list_id,
            'currency_id' => $currencyId,
            'language_id' => $address->language_id,
            'status' => 'draft',
        ]);
    }

    public function proceed()
    {
        $order = $this->findExistingOrder();
    
        if (! $order) {
            $order = $this->createOrder();
        }
    
        $this->selectedOrderId = $order->id;
        $this->storeSelection();
    
        return $this->openOrder();
    }

    public function getActionLabelProperty(): string
    {
        return $this->findExistingOrder()
            ? __('partner.open_order')
            : __('partner.load_order_sheet');
    }

    public function getCanProceedProperty(): bool
    {
        return (bool) (
            $this->seasonId
            && $this->partnerAddressId
            && $this->brandId
            && $this->orderSheetTypeId
        );
    }

    public function getCatalogGroupsProperty(): Collection
    {
        $order = $this->selectedOrder();

        if (! $order) {
            return collect();
        }

        $order->loadMissing(['partnerAddress.language', 'priceList.currency']);

        $languageCode = strtolower($order->partnerAddress?->language?->code ?? 'hu');

        $catalogGroupColumn = $languageCode === 'en'
            ? 'catalog_group_name_en'
            : 'catalog_group_name_hu';

        $fallbackCatalogGroupColumn = $languageCode === 'en'
            ? 'catalog_group_name_hu'
            : 'catalog_group_name_en';

        $products = Product::query()
            ->with(['skus.assortmentComponents'])
            ->where('season_id', $order->season_id)
            ->where('brand_id', $order->brand_id)
            ->where('order_sheet_type_id', $order->order_sheet_type_id)
            ->where('active', true)
            ->orderBy('catalog_group_sort')
            ->orderBy('catalog_sort')
            ->get();

        $orderItems = OrderItem::query()
            ->where('order_id', $order->id)
            ->get()
            ->keyBy('sku_id');

        $wholesalePrices = PriceListItem::query()
            ->where('price_list_id', $order->price_list_id)
            ->where('season_id', $order->season_id)
            ->pluck('net_price', 'product_id');

        $retailPrices = collect();

        if ($order->priceList?->retail_price_list_id) {
            $retailPrices = PriceListItem::query()
                ->where('price_list_id', $order->priceList->retail_price_list_id)
                ->where('season_id', $order->season_id)
                ->pluck('net_price', 'product_id');
        }

        return $products
            ->map(function (Product $product) use (
                $catalogGroupColumn,
                $fallbackCatalogGroupColumn,
                $orderItems,
                $wholesalePrices,
                $retailPrices
            ) {
                $groupName = $product->{$catalogGroupColumn}
                    ?: $product->{$fallbackCatalogGroupColumn};

                if (! $groupName) {
                    return null;
                }

                $quantity = 0;

                foreach ($product->skus as $sku) {
                    $orderItem = $orderItems->get($sku->id);

                    if (! $orderItem) {
                        continue;
                    }

                    $assortmentContent = (int) $sku->assortmentComponents->sum('quantity');

                    $quantity += (int) $orderItem->quantity * ($assortmentContent > 0 ? $assortmentContent : 1);
                }

                $wholesalePrice = (float) ($wholesalePrices[$product->id] ?? 0);
                $retailPrice = (float) ($retailPrices[$product->id] ?? 0);

                return [
                    'name' => $groupName,
                    'sort_order' => (int) ($product->catalog_group_sort ?? 0),
                    'quantity' => $quantity,
                    'wholesale_value' => $quantity * $wholesalePrice,
                    'retail_value' => $quantity * $retailPrice,
                ];
            })
            ->filter()
            ->groupBy('name')
            ->map(fn (Collection $rows, string $name) => [
                'name' => $name,
                'sort_order' => (int) $rows->min('sort_order'),
                'quantity' => (int) $rows->sum('quantity'),
                'wholesale_value' => (float) $rows->sum('wholesale_value'),
                'retail_value' => (float) $rows->sum('retail_value'),
            ])
            ->sortBy([
                ['sort_order', 'asc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    public function getIsSalesRepProperty(): bool
    {
        return auth('partner')->user()?->role === PartnerUser::ROLE_SALES_REP;
    }

    public function getSalesRepOrderSummariesProperty(): Collection
    {
        if ($this->salesRepOrderSummariesCache instanceof Collection) {
            return $this->salesRepOrderSummariesCache;
        }
        if ($this->salesRepOrderSummariesData !== null) {
            return $this->salesRepOrderSummariesCache = collect(
                $this->salesRepOrderSummariesData
            );
        }

        $startedAt = microtime(true);

        $orders = $this->accessibleOrders;

        $summaries = app(
            SalesRepOrderSummaryService::class
        )->build($orders);

        Log::info('OrderSelector performance: summary service', [
            'duration_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
            'order_count' => $orders->count(),
            'summary_count' => $summaries->count(),
            'partner_user_id' => auth('partner')->id(),
        ]);

        return $this->salesRepOrderSummariesCache = $summaries;
    }

    public function getFilteredSalesRepOrderSummariesProperty(): Collection
    {
        if (
            $this->filteredSalesRepOrderSummariesCache
            instanceof Collection
        ) {
            return $this->filteredSalesRepOrderSummariesCache;
        }

        $startedAt = microtime(true);

        $summaries = $this->salesRepOrderSummaries
            ->when(
                $this->summarySearch,
                function (Collection $summaries) {
                    $search = mb_strtolower(
                        trim($this->summarySearch)
                    );

                    return $summaries->filter(
                        function (array $summary) use ($search): bool {
                            return str_contains(
                                mb_strtolower(
                                    (string) (
                                        $summary['partner_code'] ?? ''
                                    )
                                ),
                                $search
                            )
                                || str_contains(
                                    mb_strtolower(
                                        (string) (
                                            $summary['partner_name'] ?? ''
                                        )
                                    ),
                                    $search
                                )
                                || str_contains(
                                    mb_strtolower(
                                        (string) (
                                            $summary['address_name'] ?? ''
                                        )
                                    ),
                                    $search
                                )
                                || str_contains(
                                    mb_strtolower(
                                        (string) (
                                            $summary['address'] ?? ''
                                        )
                                    ),
                                    $search
                                );
                        }
                    );
                }
            )
            ->when(
                $this->summarySeasonId,
                fn (Collection $summaries) => $summaries->where(
                    'season_id',
                    (int) $this->summarySeasonId
                )
            )
            ->when(
                $this->summaryBrandId,
                fn (Collection $summaries) => $summaries->where(
                    'brand_id',
                    (int) $this->summaryBrandId
                )
            )
            ->when(
                $this->summaryOrderSheetTypeId,
                fn (Collection $summaries) => $summaries->where(
                    'order_sheet_type_id',
                    (int) $this->summaryOrderSheetTypeId
                )
            )
            ->when(
                $this->summaryFilledFilter === 'filled',
                fn (Collection $summaries) => $summaries->filter(
                    fn (array $summary): bool =>
                        (int) ($summary['quantity'] ?? 0) > 0
                )
            )
            ->when(
                $this->summaryFilledFilter === 'empty',
                fn (Collection $summaries) => $summaries->filter(
                    fn (array $summary): bool =>
                        (int) ($summary['quantity'] ?? 0) === 0
                )
            )
            ->when(
                $this->summaryCurrency,
                fn (Collection $summaries) => $summaries->where(
                    'currency',
                    $this->summaryCurrency
                )
            )
            ->values();

        Log::info('OrderSelector performance: summary filtering', [
            'duration_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
            'source_count' => $this->salesRepOrderSummaries->count(),
            'filtered_count' => $summaries->count(),
            'partner_user_id' => auth('partner')->id(),
        ]);

        return $this->filteredSalesRepOrderSummariesCache = $summaries;
    }

    public function openSummaryOrder(int $orderId)
    {
        $order = $this->accessibleOrders->firstWhere('id', $orderId);

        abort_unless($order instanceof Order, 403);

        $this->selectedOrderId = (int) $order->id;
        $this->seasonId = (int) $order->season_id;
        $this->brandId = (int) $order->brand_id;
        $this->partnerAddressId = (int) $order->partner_address_id;
        $this->orderSheetTypeId = (int) $order->order_sheet_type_id;

        $this->storeSelection();

        $firstGroup = $this->catalogGroups->first();

        abort_unless($firstGroup && ! empty($firstGroup['name']), 404);

        return redirect()->route('partner.catalog-group-order', [
            'order' => $order,
            'catalogGroupName' => $firstGroup['name'],
        ]);
    }

    public function openCatalogGroup(string $catalogGroupName)
    {
        $order = $this->selectedOrder();

        abort_unless($order instanceof Order, 403);

        return redirect()->route('partner.catalog-group-order', [
            'order' => $order,
            'catalogGroupName' => $catalogGroupName,
        ]);
    }

    public function openOrder()
    {
        $order = $this->selectedOrder();

        abort_unless($order instanceof Order, 403);

        $firstGroup = $this->catalogGroups->first();

        abort_unless($firstGroup && ! empty($firstGroup['name']), 404);

        return redirect()->route('partner.catalog-group-order', [
            'order' => $order,
            'catalogGroupName' => $firstGroup['name'],
        ]);
    }

    public function submitSelectedOrder(): void
    {
        $order = $this->selectedOrder();
    
        abort_unless($order instanceof Order, 403);
    
        if ($order->isSubmitted()) {
            return;
        }
    
        $order->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    
        $order->refresh();
        $this->clearSummaryCaches();
    
        $partnerUser = auth('partner')->user();
    
        if ($partnerUser?->email) {
            $export = new PartnerOrderMatrixExport($order);
    
            $filename = method_exists($export, 'filename')
                ? $export->filename()
                : 'elorendeles-' . $order->id . '.xlsx';
    
            $excelContent = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
            
            $subject = implode(' | ', array_filter([
            $order->partner?->erp_partner_code,
            $order->partner?->name,
            $order->partnerAddress?->name,
            $order->brand?->name,
            app()->getLocale() === 'en'
                ? ($order->orderSheetType?->name_en ?? $order->orderSheetType?->name_hu)
                : ($order->orderSheetType?->name_hu ?? $order->orderSheetType?->name_en),
        ]));
            $subject = __('partner.order_submitted_email_subject') . ' | ' . $subject;
            Mail::raw(__('partner.order_submitted_email_body'), function ($message) use (
                $partnerUser,
                $filename,
                $excelContent,
                $subject
            ) {
                $message
                    ->to($partnerUser->email)
                    ->cc(config('app.order_notification_cc'))
                    ->subject($subject)
                    ->attachData(
                        $excelContent,
                        $filename,
                        [
                            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ]
                    );
            });
        }
    
        $this->dispatch('$refresh');
    }

    public function resetSelectedOrderToDraft(): void
    {


        $order = $this->selectedOrder();

        abort_unless($order instanceof Order, 403);

        if (! $order->isSubmitted()) {
            return;
        }

        $order->update([
            'status' => 'draft',
            'submitted_at' => null,
        ]);
        $this->clearSummaryCaches();
        $this->dispatch('$refresh');
    }


    public function updatedSelectedSummaryOrderIds(): void
    {
        $this->selectedSummaryOrderIds = collect($this->selectedSummaryOrderIds)
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function updatedSummarySearch(): void
    {
        $this->selectedSummaryOrderIds = [];
    }

    public function updatedSummarySeasonId(): void
    {
        $this->selectedSummaryOrderIds = [];
    }

    public function updatedSummaryBrandId(): void
    {
        $this->selectedSummaryOrderIds = [];
    }

    public function updatedSummaryOrderSheetTypeId(): void
    {
        $this->selectedSummaryOrderIds = [];
    }

    public function selectAllSummaryOrders(): void
    {
//        abort_unless($this->isSalesRep, 403);

        $this->selectedSummaryOrderIds = $this->filteredSalesRepOrderSummaries
            ->pluck('order_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function clearSelectedSummaryOrders(): void
    {
        $this->selectedSummaryOrderIds = [];
    }

    public function openSelectedSummaryOrders()
    {
//        abort_unless($this->isSalesRep, 403);

        $selectedOrderIds = collect($this->selectedSummaryOrderIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($selectedOrderIds->isEmpty()) {
            $this->addError('selectedSummaryOrderIds', __('partner.select_at_least_one_order_for_summary'));

            return null;
        }

        $orders = $this->accessibleOrders
            ->whereIn('id', $selectedOrderIds)
            ->values();

        if ($orders->isEmpty()) {
            $this->addError('selectedSummaryOrderIds', __('partner.selected_orders_not_available'));

            return null;
        }

        $combinationCount = $orders
            ->map(fn (Order $order) => implode('-', [
                $order->season_id,
                $order->brand_id,
                $order->order_sheet_type_id,
            ]))
            ->unique()
            ->count();

        if ($combinationCount !== 1) {
            $this->addError('selectedSummaryOrderIds', __('partner.only_same_summary_combination_allowed'));

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
//        abort_unless($this->isSalesRep, 403);

        return Excel::download(
            new SalesRepSummaryExport($this->filteredSalesRepOrderSummaries),
            __('partner.sales_rep_export_filename') . '-' . now()->format('Ymd-His') . '.xlsx'
        );
    }

    protected function clearInvalidSessionSelection(): void
    {
        if (! $this->partnerAddressId) {
            return;
        }
    
        $allowedAddressIds = $this->addresses
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    
        if (! in_array((int) $this->partnerAddressId, $allowedAddressIds, true)) {
            $this->seasonId = null;
            $this->brandId = null;
            $this->partnerAddressId = null;
            $this->orderSheetTypeId = null;
            $this->selectedOrderId = null;
    
            session()->forget([
                'partner_order_selector.season_id',
                'partner_order_selector.brand_id',
                'partner_order_selector.partner_address_id',
                'partner_order_selector.order_sheet_type_id',
                'partner_order_selector.order_id',
            ]);
        }
    }

    protected function storeSelection(): void
    {
        session([
            'partner_order_selector.season_id' => $this->seasonId,
            'partner_order_selector.brand_id' => $this->brandId,
            'partner_order_selector.partner_address_id' => $this->partnerAddressId,
            'partner_order_selector.order_sheet_type_id' => $this->orderSheetTypeId,
            'partner_order_selector.order_id' => $this->selectedOrderId,
        ]);
    }

    protected function autoSelectSingleOptions(): void
    {
        if (! $this->seasonId && $this->seasons->count() === 1) {
            $this->seasonId = $this->seasons->first()->id;
        }
    
        if (! $this->partnerAddressId && $this->addresses->count() === 1) {
            $this->partnerAddressId = $this->addresses->first()['id'] ?? null;
        }
    
        if (! $this->brandId && $this->brands->count() === 1) {
            $this->brandId = $this->brands->first()->id;
        }
    
        if (! $this->orderSheetTypeId && $this->orderSheetTypes->count() === 1) {
            $this->orderSheetTypeId = $this->orderSheetTypes->first()->id;
        }
    
        if (
            ! $this->selectedOrderId
            && $this->seasonId
            && $this->brandId
            && $this->partnerAddressId
            && $this->orderSheetTypeId
        ) {
            $order = $this->findExistingOrder();
    
            $this->selectedOrderId = $order?->id;
        }
    
        $this->storeSelection();
    }


    protected function canUseAddress(PartnerAddress $address): bool
    {
        $partnerUser = auth('partner')->user();

        if (! $partnerUser) {
            return false;
        }

        return $this->accessibleAddressesQuery($partnerUser)
            ->where('partner_addresses.id', $address->id)
            ->exists();
    }

    protected function findExistingOrder(): ?Order
    {
        if (
            ! $this->seasonId ||
            ! $this->brandId ||
            ! $this->partnerAddressId ||
            ! $this->orderSheetTypeId
        ) {
            return null;
        }
    
        return Order::query()
            ->with([
                'season',
                'brand',
                'partner',
                'partnerAddress.language',
                'orderSheetType',
                'priceList.currency',
            ])
            ->where('season_id', $this->seasonId)
            ->where('partner_address_id', $this->partnerAddressId)
            ->where('brand_id', $this->brandId)
            ->where('order_sheet_type_id', $this->orderSheetTypeId)
            ->first();
    }

    public function render()
    {
        $this->autoSelectSingleOptions();
    
        $this->setPartnerLocale(
            $this->partnerAddressId ? (int) $this->partnerAddressId : null
        );
    
        $summarySeasons = $this->summaryLoaded
            ? $this->salesRepOrderSummaries
            ->map(fn (array $summary) => [
                'id' => (int) ($summary['season_id'] ?? 0),
                'name' => (string) ($summary['season'] ?? ''),
            ])
            ->filter(fn (array $season) => $season['id'] > 0)
            ->unique('id')
            ->sortBy('name')
            ->values()
            : collect();

        $summaryBrands = $this->summaryLoaded
            ? $this->salesRepOrderSummaries
            ->map(fn (array $summary) => [
                'id' => (int) ($summary['brand_id'] ?? 0),
                'name' => (string) ($summary['brand'] ?? ''),
            ])
            ->filter(fn (array $brand) => $brand['id'] > 0)
            ->unique('id')
            ->sortBy('name')
            ->values()
            : collect();

        $summaryOrderSheetTypes = $this->summaryLoaded
            ? $this->salesRepOrderSummaries
            ->map(fn (array $summary) => [
                'id' => (int) ($summary['order_sheet_type_id'] ?? 0),
                'name' => (string) ($summary['type'] ?? ''),
            ])
            ->filter(fn (array $type) => $type['id'] > 0)
            ->unique('id')
            ->sortBy('name')
            ->values()
            : collect();

        return view('livewire.partner.order-selector', [
            'summarySeasons' => $summarySeasons,
            'summaryBrands' => $summaryBrands,
            'summaryOrderSheetTypes' => $summaryOrderSheetTypes,
        ])->layout('components.layouts.app');
    }
}
