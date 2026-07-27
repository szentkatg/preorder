<?php

namespace App\Livewire\Partner;

use App\Exports\PartnerOrderMatrixExport;
use App\Livewire\Partner\Concerns\HandlesMultiAddressExcelImport;
use App\Models\Catalog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Sku;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

class CatalogGroupOrder extends Component
{
    use HandlesMultiAddressExcelImport;
    use WithFileUploads;

    protected ?array $matrixGroupsCache = null;

    public ?string $scrollToModelCode = null;

    public string $modelSearch = '';

    public array $modelSearchResults = [];

    public int $modelSearchIndex = -1;

    public Order $order;

    public string $catalogGroupName;

    //    public array $matrixGroups = [];

    public array $pieceQuantities = [];

    public array $assortmentQuantities = [];

    public bool $allowAssortmentOrdering = false;

    public bool $showAllCatalogGroups = false;

    public ?int $retailPriceListId = null;

    public ?string $previousCatalogGroup = null;

    public ?string $nextCatalogGroup = null;

    public bool $showImageModal = false;

    public ?string $imageModalTitle = null;

    public array $imageModalImages = [];

    public array $excelFiles = [];

    public function deleteOrder(): void
    {
        if ($this->order->isSubmitted()) {
            return;
        }

        $this->order->items()->delete();
        $this->order->delete();

        $this->redirectRoute('partner.orders.select', navigate: true);
    }

    protected function matrixGroups(): array
    {
        if ($this->matrixGroupsCache !== null) {
            return $this->matrixGroupsCache;
        }

        return $this->matrixGroupsCache = $this->buildMatrixGroups();
    }

    #[Renderless]
    public function savePieceQuantity(int $skuId, mixed $value): void
    {
        if ($this->order->isSubmitted()) {
            return;
        }

        $quantity = max(0, (int) $value);

        $this->pieceQuantities[$skuId] = $quantity;

        $this->saveQuantities([
            $skuId => $quantity,
        ]);

        $this->skipRender();
    }

    #[Renderless]
    public function saveAssortmentQuantity(int $skuId, mixed $value): void
    {
        if ($this->order->isSubmitted()) {
            return;
        }

        $quantity = max(0, (int) $value);

        $this->assortmentQuantities[$skuId] = $quantity;

        $this->saveQuantities([
            $skuId => $quantity,
        ]);

        $this->skipRender();
    }

    public function updatedModelSearch(): void
    {
        $this->modelSearchResults = [];
        $this->modelSearchIndex = -1;
    }

    public function jumpToFirstModelSearchResult(): void
    {
        $this->modelSearchResults = [];
        $this->modelSearchIndex = -1;

        $this->jumpToNextModelSearchResult();
    }

    public function getOrderSummary(): array
    {
        $quantity = 0;
        $wholesaleValue = 0;
        $retailValue = 0;

        foreach ($this->order->items()->with('sku.product')->get() as $item) {
            $itemQuantity = (int) $item->quantity;

            $wholesalePrice = $this->getProductPrice(
                $item->sku?->product_id,
                $this->order->price_list_id
            );

            $retailPrice = $this->retailPriceListId
                ? $this->getProductPrice($item->sku?->product_id, $this->retailPriceListId)
                : 0;

            $quantity += $itemQuantity;
            $wholesaleValue += $itemQuantity * $wholesalePrice;
            $retailValue += $itemQuantity * $retailPrice;
        }

        return [
            'quantity' => $quantity,
            'wholesale_value' => $wholesaleValue,
            'retail_value' => $retailValue,
        ];
    }

    public function jumpToNextModelSearchResult(): void
    {
        $search = trim($this->modelSearch ?? '');

        if ($search === '') {
            $this->modelSearchResults = [];
            $this->modelSearchIndex = -1;

            return;
        }

        if (empty($this->modelSearchResults)) {
            $products = Product::query()
                ->where('season_id', $this->order->season_id)
                ->where('brand_id', $this->order->brand_id)
                ->where('order_sheet_type_id', $this->order->order_sheet_type_id)
                ->where('active', true)
                ->where(function ($query) use ($search) {
                    $like = '%'.$search.'%';

                    $query
                        ->where('model_code', 'like', $like)
                        ->orWhere('name_hu', 'like', $like)
                        ->orWhere('name_en', 'like', $like);
                })
                ->orderBy('catalog_group_sort')
                ->orderBy('catalog_sort')
                ->get();

            $catalogGroupColumn = $this->catalogGroupNameColumn();

            $this->modelSearchResults = $products
                ->map(function (Product $product) use ($catalogGroupColumn) {
                    return [
                        'catalog_group_name' => $product->{$catalogGroupColumn},
                        'model_code' => $product->model_code,
                    ];
                })
                ->filter(fn ($row) => filled($row['catalog_group_name']) && filled($row['model_code']))
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

        $this->scrollToModelCode = $result['model_code'];

        $this->navigateToCatalogGroup($result['catalog_group_name']);

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

    public function mount(Order $order, string $catalogGroupName): void
    {
        $partnerUser = auth('partner')->user();

        abort_unless($partnerUser && $partnerUser->canAccessOrder($order), 403);

        $this->order = $order->load([
            'partner',
            'partnerAddress.language',
            'priceList',
            'priceList.currency',
            'items.sku.assortmentComponents',
        ]);

        $this->setPartnerLocale();

        $this->catalogGroupName = urldecode($catalogGroupName);

        $this->retailPriceListId = $this->order->priceList?->retail_price_list_id;

        $this->allowAssortmentOrdering = (bool) $this->order->partnerAddress?->allow_assortment_ordering;

        $this->loadExistingQuantities();

        $this->buildMatrixGroups();

        $this->loadNavigation();
    }

    public function updatedShowAllCatalogGroups(): void
    {
        $this->buildMatrixGroups();
    }

    protected function setPartnerLocale(): void
    {
        app()->setLocale((string) session('partner.locale', 'hu'));
    }

    protected function catalogGroupNameColumn(): string
    {
        return app()->getLocale() === 'en'
            ? 'catalog_group_name_en'
            : 'catalog_group_name_hu';
    }

    protected function productNameColumn(): string
    {
        return app()->getLocale() === 'en'
            ? 'name_en'
            : 'name_hu';
    }

    protected function colorNameColumn(): string
    {
        return app()->getLocale() === 'en'
            ? 'name_en'
            : 'name_hu';
    }

    protected function localizedValue(object $model, string $baseName): string
    {
        $locale = app()->getLocale() === 'en' ? 'en' : 'hu';
        $fallback = $locale === 'en' ? 'hu' : 'en';

        return (string) (
            $model->{$baseName.'_'.$locale}
            ?? $model->{$baseName.'_'.$fallback}
            ?? ''
        );
    }

    protected function loadExistingQuantities(): void
    {
        $this->order->items->each(function (OrderItem $item) {
            if ($item->sku?->assortmentComponents?->isNotEmpty()) {
                $this->assortmentQuantities[$item->sku_id] = $item->quantity;
            } else {
                $this->pieceQuantities[$item->sku_id] = $item->quantity;
            }
        });
    }

    protected function buildMatrixGroups(): array
    {
        $catalogGroupColumn = $this->catalogGroupNameColumn();

        $query = Product::query()
            ->with([
                'sizeRange.items.size',
                'colors.skus.size',
                'colors.itemAssortments.assortmentSku',
                'colors.itemAssortments.componentSku.size',
            ])
            ->where('season_id', $this->order->season_id)
            ->where('brand_id', $this->order->brand_id)
            ->where('order_sheet_type_id', $this->order->order_sheet_type_id)
            ->where('active', true);

        if ($this->showAllCatalogGroups) {
            $query
                ->whereNotNull($catalogGroupColumn)
                ->where($catalogGroupColumn, '!=', '');
        } else {
            $query->where($catalogGroupColumn, $this->catalogGroupName);
        }

        $products = $query
            ->orderBy('catalog_group_sort')
            ->orderBy('catalog_sort')
            ->orderBy('model_code')
            ->get();

        $groups = collect();

        foreach ($products->groupBy(fn (Product $product) => $product->{$catalogGroupColumn} ?: 'EGYEB') as $catalogGroupName => $catalogProducts) {
            foreach ($catalogProducts->groupBy(fn (Product $product) => $product->sizeRange?->matrix_group ?: 'EGYEB') as $matrixGroup => $matrixProducts) {

                $sizes = $matrixProducts
                    ->pluck('sizeRange')
                    ->filter()
                    ->flatMap(fn ($sizeRange) => $sizeRange->items)
                    ->filter(fn ($item) => $item->size)
                    ->map(fn ($item) => [
                        'id' => (int) $item->size->id,
                        'code' => (string) $item->size->code,

                        /*
                         * Elsődlegesen a sizes tábla order mezőjét használjuk.
                         * Ha nálad mégis sort_order a mező neve a sizes táblában,
                         * akkor az alábbi sorban az order maradhat fallback előtt.
                         */
                        'sort_order' => (int) (
                            $item->size->order
                            ?? $item->size->sort_order
                            ?? $item->sort_order
                            ?? 0
                        ),
                    ])
                    ->unique('id')
                    ->sortBy([
                        fn ($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0),
                        fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code']),
                    ])
                    ->values()
                    ->all();

                $groups->push([
                    'catalog_group' => $catalogGroupName,
                    'matrix_group' => $this->showAllCatalogGroups
                        ? $catalogGroupName.' / '.$matrixGroup
                        : $matrixGroup,
                    'raw_matrix_group' => $matrixGroup,
                    'sizes' => $sizes,
                    'products' => $matrixProducts
                        ->map(fn (Product $product) => $this->formatProduct($product))
                        ->values()
                        ->all(),
                ]);
            }
        }

        return $groups->values()->all();
    }

    protected function formatProduct(Product $product): array
    {
        $wholesalePrice = $this->getProductPrice($product->id, $this->order->price_list_id);

        $retailPrice = $this->retailPriceListId
            ? $this->getProductPrice($product->id, $this->retailPriceListId)
            : 0;

        return [
            'id' => $product->id,
            'model_code' => $product->model_code,
            'catalog_page' => $product->catalog_page ?? null,
            'name' => $this->localizedValue($product, 'name'),
            'price' => $wholesalePrice,
            'retail_price' => $retailPrice,
            'colors' => $product->colors
                ->where('active', true)
                ->sortBy('sort_order')
                ->map(fn ($color) => [
                    'id' => $color->id,
                    'code' => $color->code,
                    'name' => $this->localizedValue($color, 'name'),
                    'sku_map' => $color->skus
                        ->where('active', true)
                        ->mapWithKeys(fn ($sku) => [
                            $sku->size_id => [
                                'id' => $sku->id,
                                'code' => $sku->sku_code,
                            ],
                        ])
                        ->all(),
                    'assortments' => $this->formatAssortments($color),
                ])
                ->values()
                ->all(),
        ];
    }

    protected function getProductPrice(int $productId, ?int $priceListId): float
    {
        if (! $priceListId) {
            return 0;
        }

        return (float) (
            PriceListItem::query()
                ->where('price_list_id', $priceListId)
                ->where('product_id', $productId)
                ->where('season_id', $this->order->season_id)
                ->value('net_price') ?? 0
        );
    }

    protected function loadNavigation(): void
    {
        $catalogGroupColumn = $this->catalogGroupNameColumn();

        $groups = Product::query()
            ->where('season_id', $this->order->season_id)
            ->where('brand_id', $this->order->brand_id)
            ->where('order_sheet_type_id', $this->order->order_sheet_type_id)
            ->where('active', true)
            ->whereNotNull($catalogGroupColumn)
            ->where($catalogGroupColumn, '!=', '')
            ->selectRaw("{$catalogGroupColumn}, MIN(catalog_group_sort) as sort_order")
            ->groupBy($catalogGroupColumn)
            ->orderBy('sort_order')
            ->pluck($catalogGroupColumn)
            ->values();

        $currentIndex = $groups->search($this->catalogGroupName);

        if ($currentIndex === false) {
            return;
        }

        $this->previousCatalogGroup =
            $currentIndex > 0
                ? $groups[$currentIndex - 1]
                : null;

        $this->nextCatalogGroup =
            $currentIndex < ($groups->count() - 1)
                ? $groups[$currentIndex + 1]
                : null;
    }

    protected function formatAssortments($color): array
    {
        return $color->itemAssortments
            ->groupBy('assortment_sku_id')
            ->map(function ($items, int $assortmentSkuId) {
                $assortmentSku = $items->first()->assortmentSku;

                return [
                    'sku_id' => $assortmentSkuId,
                    'code' => $assortmentSku?->sku_code,
                    'content' => $items
                        ->mapWithKeys(fn ($item) => [
                            $item->component_sku_id => (int) $item->quantity,
                        ])
                        ->all(),
                    'content_by_size' => $items
                        ->mapWithKeys(fn ($item) => [
                            $item->componentSku?->size_id => (int) $item->quantity,
                        ])
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    public function getTotalForSku(int $skuId): int
    {
        $total = (int) ($this->pieceQuantities[$skuId] ?? 0);

        foreach ($this->matrixGroups() as $matrixGroup) {
            foreach ($matrixGroup['products'] as $product) {
                foreach ($product['colors'] as $color) {
                    foreach ($color['assortments'] as $assortment) {
                        $assortmentQty = (int) ($this->assortmentQuantities[$assortment['sku_id']] ?? 0);
                        $componentQty = (int) ($assortment['content'][$skuId] ?? 0);

                        $total += $assortmentQty * $componentQty;
                    }
                }
            }
        }

        return $total;
    }

    public function getRowTotal(array $color): int
    {
        $total = 0;

        foreach ($color['sku_map'] as $sku) {
            $total += $this->getTotalForSku($sku['id']);
        }

        return $total;
    }

    public function getRowWholesaleValue(array $product, array $color): float
    {
        return $this->getRowTotal($color) * (float) ($product['price'] ?? 0);
    }

    public function getRowRetailValue(array $product, array $color): float
    {
        return $this->getRowTotal($color) * (float) ($product['retail_price'] ?? 0);
    }

    public function getCurrentGroupSummary(): array
    {
        $quantity = 0;
        $wholesaleValue = 0;
        $retailValue = 0;

        foreach ($this->matrixGroups() as $matrixGroup) {
            foreach ($matrixGroup['products'] as $product) {
                foreach ($product['colors'] as $color) {
                    $rowQty = $this->getRowTotal($color);

                    $quantity += $rowQty;
                    $wholesaleValue += $rowQty * (float) ($product['price'] ?? 0);
                    $retailValue += $rowQty * (float) ($product['retail_price'] ?? 0);
                }
            }
        }

        return [
            'quantity' => $quantity,
            'wholesale_value' => $wholesaleValue,
            'retail_value' => $retailValue,
        ];
    }

    protected function getCurrentGroupSkuIds(): array
    {
        $skuIds = [];

        foreach ($this->matrixGroups() as $matrixGroup) {
            foreach ($matrixGroup['products'] as $product) {
                foreach ($product['colors'] as $color) {
                    foreach ($color['sku_map'] as $sku) {
                        $skuIds[] = (int) $sku['id'];
                    }

                    foreach ($color['assortments'] as $assortment) {
                        $skuIds[] = (int) $assortment['sku_id'];
                    }
                }
            }
        }

        return array_values(array_unique($skuIds));
    }

    public function getFullOrderSummary(): array
    {
        $currentGroupSummary = $this->getCurrentGroupSummary();
        $currentGroupSkuIds = $this->getCurrentGroupSkuIds();

        $quantity = $currentGroupSummary['quantity'];
        $wholesaleValue = $currentGroupSummary['wholesale_value'];
        $retailValue = $currentGroupSummary['retail_value'];

        $items = OrderItem::query()
            ->with('sku.assortmentComponents')
            ->where('order_id', $this->order->id)
            ->whereNotIn('sku_id', $currentGroupSkuIds)
            ->get();

        foreach ($items as $item) {
            if (! $item->sku) {
                continue;
            }

            $assortmentContent = (int) $item->sku->assortmentComponents->sum('quantity');

            $effectiveQuantity = $assortmentContent > 0
                ? (int) $item->quantity * $assortmentContent
                : (int) $item->quantity;

            $wholesalePrice = $this->getProductPrice(
                $item->sku->product_id,
                $this->order->price_list_id
            );

            $retailPrice = $this->retailPriceListId
                ? $this->getProductPrice($item->sku->product_id, $this->retailPriceListId)
                : 0;

            $quantity += $effectiveQuantity;
            $wholesaleValue += $effectiveQuantity * $wholesalePrice;
            $retailValue += $effectiveQuantity * $retailPrice;
        }

        return [
            'quantity' => $quantity,
            'wholesale_value' => $wholesaleValue,
            'retail_value' => $retailValue,
        ];
    }

    public function getCurrencySymbol(): string
    {
        return $this->order->priceList?->currency?->symbol ?? '';
    }

    public function openProductImages(int $productId): void
    {
        $product = Product::query()
            ->with([
                'colorImages.color',
            ])
            ->find($productId);

        if (! $product) {
            return;
        }

        $this->imageModalTitle = $product->model_code.' - '.$this->localizedValue($product, 'name');

        $images = collect();

        $catalog = Catalog::query()
            ->where('season_id', $this->order->season_id)
            ->where('brand_id', $this->order->brand_id)
            ->where('order_sheet_type_id', $this->order->order_sheet_type_id)
            ->where('active', true)
            ->first();

        if ($catalog && $product->catalog_page) {
            $pageNumber = (int) $product->catalog_page + (int) $catalog->page_offset;

            if ($pageNumber > 0) {
                $images->push([
                    'url' => asset(
                        'catalog-pages/'.
                        trim($catalog->image_folder, '/').
                        '/page-'.
                        str_pad($pageNumber, 3, '0', STR_PAD_LEFT).
                        '.jpg'
                    ),
                    'color_name' => __('partner.catalog_page').': '.$product->catalog_page,
                    'type' => 'catalog',
                ]);
            }
        }

        $product->colorImages
            ->where('active', true)
            ->sortBy('sort_order')
            ->each(function ($image) use ($images, $product) {
                $images->push([
                    'url' => str_starts_with($image->image_url, 'http')
                        ? $image->image_url
                        : asset(ltrim($image->image_url, '/')),
                    'color_name' => $image->color
                        ? $this->localizedValue($image->color, 'name')
                        : $this->localizedValue($product, 'name'),
                    'type' => 'product',
                ]);
            });

        $this->imageModalImages = $images
            ->values()
            ->all();

        $this->showImageModal = true;
    }

    public function closeImageModal(): void
    {
        $this->showImageModal = false;
        $this->imageModalTitle = null;
        $this->imageModalImages = [];
    }

    public function exportExcel()
    {
        $export = new PartnerOrderMatrixExport($this->order);

        return Excel::download(
            $export,
            $export->filename()
        );
    }

    public function save(): void
    {
        if ($this->order->isSubmitted()) {
            return;
        }

        $this->saveQuantities($this->pieceQuantities);
        $this->saveQuantities($this->assortmentQuantities);

        $this->order->refresh();

        session()->flash('success', __('partner.order_saved'));
    }

    protected function saveQuantities(array $quantities): void
    {
        foreach ($quantities as $skuId => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity <= 0) {
                OrderItem::query()
                    ->where('order_id', $this->order->id)
                    ->where('sku_id', $skuId)
                    ->delete();

                continue;
            }

            $sku = Sku::query()
                ->with('assortmentComponents')
                ->find($skuId);

            if (! $sku) {
                continue;
            }

            $unitPrice = $this->getProductPrice($sku->product_id, $this->order->price_list_id);

            $assortmentContent = (int) $sku->assortmentComponents->sum('quantity');

            $lineTotal = $assortmentContent > 0
                ? $quantity * $assortmentContent * $unitPrice
                : $quantity * $unitPrice;

            OrderItem::updateOrCreate(
                [
                    'order_id' => $this->order->id,
                    'sku_id' => $skuId,
                ],
                [
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ]
            );
        }
    }

    public function navigateToCatalogGroup(
        string $catalogGroupName
    ) {
        return redirect()->route(
            'partner.catalog-group-order',
            [
                'order' => $this->order->id,
                'catalogGroupName' => $catalogGroupName,
            ]
        );
    }

    public function backToCatalogGroups()
    {
        return redirect()->route('partner.orders.select');
    }

    public function render()
    {
        $this->setPartnerLocale();

        return view('livewire.partner.catalog-group-order', [
            'matrixGroups' => $this->matrixGroups(),
        ])->layout('components.layouts.app');
    }
}
