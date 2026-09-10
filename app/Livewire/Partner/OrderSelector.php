<?php

namespace App\Livewire\Partner;

use App\Exports\PartnerOrderMatrixExport;
use App\Exports\SalesRepSummaryExport;
use App\Livewire\Partner\Concerns\SetsPartnerLocale;
use App\Models\Brand;
use App\Models\Language;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSheetType;
use App\Models\OrderType;
use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Season;
use App\Models\Translation;
use App\Services\Partner\SalesRepOrderSummaryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

class OrderSelector extends Component
{
    use SetsPartnerLocale;

    protected ?Collection $accessibleOrdersCache = null;

    protected ?Collection $salesRepOrderSummariesCache = null;

    protected ?Collection $filteredSalesRepOrderSummariesCache = null;

    protected float $requestStartedAt = 0.0;

    public ?int $seasonId = null;

    public ?int $brandId = null;

    public ?int $partnerAddressId = null;

    public ?int $orderSheetTypeId = null;

    public ?int $orderTypeId = null;

    public string $referenceNumber = '';

    public ?int $selectedOrderId = null;

    public string $modelSearch = '';

    public array $modelSearchResults = [];

    public int $modelSearchIndex = -1;

    protected array $selectedSummaryOrderIds = [];

    protected string $summarySearch = '';

    public string $addressSearch = '';

    protected ?int $summarySeasonId = null;

    protected ?int $summaryBrandId = null;

    protected ?int $summaryOrderSheetTypeId = null;

    protected ?string $summaryFilledFilter = null;

    protected ?string $summaryCurrency = null;

    protected bool $summaryLoaded = false;

    protected ?array $salesRepOrderSummariesData = null;

    public function boot(): void
    {
        app()->setLocale((string) session('partner.locale', 'hu'));
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
        ]);
    }

    public function mount(): void
    {
        $this->seasonId = session('partner_order_selector.season_id');
        $this->brandId = session('partner_order_selector.brand_id');
        $this->partnerAddressId = session('partner_order_selector.partner_address_id');
        $this->orderSheetTypeId = session('partner_order_selector.order_sheet_type_id');
        $this->orderTypeId = session('partner_order_selector.order_type_id');
        $this->selectedOrderId = session('partner_order_selector.order_id');

        $this->clearInvalidSessionSelection();

        $selectedOrderId = session()->pull('order_selector.open_order_id');

        if ($selectedOrderId) {
            $this->selectedOrderId = (int) $selectedOrderId;
            $this->setPartnerLocale($this->selectedAddress);
            $this->openOrder();

            return;
        }

        $this->setPartnerLocale($this->selectedAddress);
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
                'orderType',
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
                $like = '%'.$search.'%';

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

    public function getSelectedAddressProperty(): ?PartnerAddress
    {
        if (! $this->partnerAddressId) {
            return null;
        }

        $partnerUser = auth('partner')->user();

        if (! $partnerUser) {
            return null;
        }

        return $this->accessibleAddressesQuery($partnerUser)
            ->with(['partner', 'language'])
            ->find($this->partnerAddressId);
    }

    protected function accessibleAddressesQuery(PartnerUser $partnerUser)
    {
        return $partnerUser
            ->accessibleAddressesQuery()
            ->with('partner')
            ->where('partner_addresses.active', true);
    }

    public function getBrandsProperty(): Collection
    {
        if (! $this->seasonId || ! $this->partnerAddressId) {
            return collect();
        }

        $brands = Brand::query()
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

        return $this->withTranslatedNames($brands, 'brand');
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
                                        ($product['model_code'] ?? '').' '.
                                        ($product['name'] ?? '').' '.
                                        ($product['name_hu'] ?? '').' '.
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

        $types = OrderSheetType::query()
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
            ->orderBy('name')
            ->get();

        return $this->withTranslatedNames($types, 'order_sheet_type');
    }

    public function getOrderTypesProperty(): Collection
    {
        $types = OrderType::query()
            ->where('active', true)
            ->orderBy('code')
            ->get();

        return $this->withTranslatedNames($types, 'order_type');
    }

    public function getContextOrdersProperty(): Collection
    {
        if (
            ! $this->seasonId
            || ! $this->brandId
            || ! $this->partnerAddressId
            || ! $this->orderSheetTypeId
        ) {
            return collect();
        }

        return $this->accessibleOrders
            ->where('season_id', (int) $this->seasonId)
            ->where('partner_address_id', (int) $this->partnerAddressId)
            ->where('brand_id', (int) $this->brandId)
            ->where('order_sheet_type_id', (int) $this->orderSheetTypeId)
            ->sortByDesc('created_at')
            ->values();
    }

    protected function withTranslatedNames(Collection $models, string $entity): Collection
    {
        if ($models->isEmpty()) {
            return $models;
        }

        $languageId = (int) session('partner.language_id', 0);

        if ($languageId <= 0) {
            $languageId = (int) Language::query()
                ->whereRaw('LOWER(code) = ?', [strtolower(app()->getLocale())])
                ->where('active', true)
                ->value('id');
        }

        $translations = $languageId > 0
            ? Translation::query()
                ->where('entity', $entity)
                ->where('field', 'name')
                ->where('language_id', $languageId)
                ->whereIn('entity_code', $models->pluck('code')->filter())
                ->pluck('value', 'entity_code')
            : collect();

        return $models->each(function ($model) use ($translations): void {
            $model->setAttribute(
                'translated_name',
                $translations->get($model->code) ?: $model->name
            );
        });
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

        return null;
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
        $this->resetNewOrderFields();

        $this->storeSelection();
    }

    public function updatedPartnerAddressId(): void
    {
        $this->selectedOrderId = null;
        $this->brandId = null;
        $this->orderSheetTypeId = null;
        $this->resetNewOrderFields();
        $this->setPartnerLocale($this->selectedAddress);
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
        $this->resetNewOrderFields();

        $this->storeSelection();
    }

    public function updatedOrderSheetTypeId(): void
    {
        $this->selectedOrderId = null;
        $this->resetNewOrderFields();
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
        $this->referenceNumber = trim($this->referenceNumber);

        $address = PartnerAddress::query()
            ->with(['partner', 'priceList'])
            ->findOrFail($this->partnerAddressId);

        abort_unless($this->canUseAddress($address), 403);

        $this->validate([
            'referenceNumber' => [
                'required',
                'string',
                'max:100',
                Rule::unique('orders', 'reference_number')
                    ->where(fn ($query) => $query
                        ->where('season_id', $this->seasonId)
                        ->where('partner_id', $address->partner_id)
                        ->where('partner_address_id', $address->id)
                        ->where('brand_id', $this->brandId)
                        ->where('order_sheet_type_id', $this->orderSheetTypeId)),
            ],
            'orderTypeId' => [
                'required',
                'integer',
                Rule::exists('order_types', 'id')
                    ->where('active', true),
            ],
        ], [
            'referenceNumber.required' => __('partner.reference_number_required'),
            'referenceNumber.unique' => __('partner.reference_number_already_exists'),
            'orderTypeId.required' => __('partner.order_type_required'),
            'orderTypeId.exists' => __('partner.order_type_invalid'),
        ]);

        $currencyId = $address->currency_id ?? $address->priceList?->currency_id;

        return Order::create([
            'season_id' => $this->seasonId,
            'partner_id' => $address->partner_id,
            'partner_address_id' => $address->id,
            'brand_id' => $this->brandId,
            'order_sheet_type_id' => $this->orderSheetTypeId,
            'reference_number' => trim($this->referenceNumber),
            'order_type_id' => $this->orderTypeId,
            'price_list_id' => $address->price_list_id,
            'currency_id' => $currencyId,
            'language_id' => $address->language_id,
            'status' => 'draft',
        ]);
    }

    public function proceed()
    {
        abort_unless($this->canProceed, 422);

        $order = $this->createOrder();

        $this->accessibleOrdersCache = null;

        $this->selectedOrderId = $order->id;
        $this->storeSelection();

        return $this->openOrder();
    }

    public function getActionLabelProperty(): string
    {
        return __('partner.create_order');
    }

    public function getCanProceedProperty(): bool
    {
        return (bool) (
            $this->seasonId
            && $this->partnerAddressId
            && $this->brandId
            && $this->orderSheetTypeId
            && filled($this->referenceNumber)
            && $this->orderTypeId
        );
    }

    public function openExistingOrder(int $orderId)
    {
        $order = $this->contextOrders->firstWhere('id', $orderId);

        abort_unless($order instanceof Order, 403);

        $this->selectedOrderId = (int) $order->id;
        $this->storeSelection();

        return $this->openOrder();
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
                                )
                                || str_contains(
                                    mb_strtolower(
                                        (string) (
                                            $summary['reference_number'] ?? ''
                                        )
                                    ),
                                    $search
                                )
                                || str_contains(
                                    mb_strtolower(
                                        (string) (
                                            $summary['order_type'] ?? ''
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
                    fn (array $summary): bool => (int) ($summary['quantity'] ?? 0) > 0
                )
            )
            ->when(
                $this->summaryFilledFilter === 'empty',
                fn (Collection $summaries) => $summaries->filter(
                    fn (array $summary): bool => (int) ($summary['quantity'] ?? 0) === 0
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
        $this->orderTypeId = (int) $order->order_type_id;

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
        $this->dispatch('order-summary-changed');

        $partnerUser = auth('partner')->user();

        if ($partnerUser?->email) {
            $export = new PartnerOrderMatrixExport($order);

            $filename = method_exists($export, 'filename')
                ? $export->filename()
                : 'elorendeles-'.$order->id.'.xlsx';

            $excelContent = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

            $subject = implode(' | ', array_filter([
                $order->partner?->erp_partner_code,
                $order->partner?->name,
                $order->partnerAddress?->name,
                $order->brand?->name,
                $order->orderSheetType?->translate('name'),
                $order->reference_number,
            ]));
            $subject = __('partner.order_submitted_email_subject').' | '.$subject;
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
        $this->dispatch('order-summary-changed');
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
        abort_unless($this->isSalesRep, 403);

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
        abort_unless($this->isSalesRep, 403);

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
        abort_unless($this->isSalesRep, 403);

        return Excel::download(
            new SalesRepSummaryExport($this->filteredSalesRepOrderSummaries),
            __('partner.sales_rep_export_filename').'-'.now()->format('Ymd-His').'.xlsx'
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
            $this->orderTypeId = null;
            $this->referenceNumber = '';
            $this->selectedOrderId = null;

            session()->forget([
                'partner_order_selector.season_id',
                'partner_order_selector.brand_id',
                'partner_order_selector.partner_address_id',
                'partner_order_selector.order_sheet_type_id',
                'partner_order_selector.order_type_id',
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
            'partner_order_selector.order_type_id' => $this->orderTypeId,
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

            if ($this->partnerAddressId) {
                $this->setPartnerLocale($this->selectedAddress);
            }
        }

        if (! $this->brandId && $this->brands->count() === 1) {
            $this->brandId = $this->brands->first()->id;
        }

        if (! $this->orderSheetTypeId && $this->orderSheetTypes->count() === 1) {
            $this->orderSheetTypeId = $this->orderSheetTypes->first()->id;
        }

        if (! $this->orderTypeId) {
            $this->orderTypeId = (int) ($this->orderTypes
                ->firstWhere('code', 'VRELO')?->id ?? 0) ?: null;
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

    protected function resetNewOrderFields(): void
    {
        $this->referenceNumber = '';
        $this->orderTypeId = (int) ($this->orderTypes
            ->firstWhere('code', 'VRELO')?->id ?? 0) ?: null;
    }

    public function render()
    {
        $this->autoSelectSingleOptions();

        return view('livewire.partner.order-selector')
            ->layout('components.layouts.app');
    }
}
