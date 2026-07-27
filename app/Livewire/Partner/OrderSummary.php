<?php

namespace App\Livewire\Partner;

use App\Exports\PartnerOrderSummaryMatrixExport;
use App\Models\Brand;
use App\Models\Catalog;
use App\Models\Order;
use App\Models\OrderSheetType;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Season;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

class OrderSummary extends Component
{
    public Season $season;

    public Brand $brand;

    public OrderSheetType $orderSheetType;

    public $orders;

    public string $catalogGroupName = '';

    public array $matrixGroups = [];

    public array $pieceQuantities = [];

    public array $assortmentQuantities = [];

    public array $summaryTotals = [
        'quantity' => 0,
        'wholesale_value' => 0.0,
        'retail_value' => 0.0,
    ];

    public bool $allowAssortmentOrdering = true;

    public bool $showAllCatalogGroups = true;

    public ?int $priceListId = null;

    public ?int $retailPriceListId = null;

    public bool $showImageModal = false;

    public ?string $imageModalTitle = null;

    public array $imageModalImages = [];

    public function mount(
        Season $season,
        Brand $brand,
        OrderSheetType $orderSheetType
    ): void {

        $startedAt = microtime(true);
        $checkpoint = $startedAt;

        Log::info('OrderSummary timing: mount started');

        $this->season = $season;
        $this->brand = $brand;
        $this->orderSheetType = $orderSheetType;

        $partnerUser = auth('partner')->user();

        $partnerIds = $partnerUser->partners()
            ->pluck('partners.id');

        if ($partnerIds->isEmpty() && $partnerUser->partner_id) {
            $partnerIds = collect([$partnerUser->partner_id]);
        }

        $selectedOrderIds = collect(explode(',', (string) request('orders')))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        $query = Order::query()
            ->whereIn('partner_id', $partnerIds)
            ->where('season_id', $this->season->id)
            ->where('brand_id', $this->brand->id)
            ->where('order_sheet_type_id', $this->orderSheetType->id);

        if ($selectedOrderIds->isNotEmpty()) {
            $query->whereIn('id', $selectedOrderIds);
        }

        $this->orders = $query
            ->with([
                'partnerAddress.language',
                'priceList.currency',
            ])
            ->get();

        Log::info('OrderSummary timing: orders loaded', [
            'seconds' => round(microtime(true) - $checkpoint, 3),
            'orders' => $this->orders->count(),
        ]);

        $checkpoint = microtime(true);

        $firstOrder = $this->orders->first();

        $this->priceListId = $firstOrder?->price_list_id;
        $this->retailPriceListId = $firstOrder?->priceList?->retail_price_list_id;
        $this->setPartnerLocale();
        $this->loadExistingQuantities();

        Log::info('OrderSummary timing: quantities loaded', [
            'seconds' => round(microtime(true) - $checkpoint, 3),
            'piece_skus' => count($this->pieceQuantities),
            'assortment_skus' => count($this->assortmentQuantities),
        ]);

        $checkpoint = microtime(true);

        $this->allowAssortmentOrdering = collect(
            $this->assortmentQuantities
        )->sum() > 0;

        $this->buildMatrixGroups();

        Log::info('OrderSummary timing: matrix groups built', [
            'seconds' => round(microtime(true) - $checkpoint, 3),
            'matrix_groups' => count($this->matrixGroups),
        ]);

        $checkpoint = microtime(true);

        $this->calculateMatrixTotals();

        Log::info('OrderSummary timing: totals calculated', [
            'seconds' => round(microtime(true) - $checkpoint, 3),
        ]);

        Log::info('OrderSummary timing: mount finished', [
            'seconds' => round(microtime(true) - $startedAt, 3),
        ]);
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
        $this->pieceQuantities = [];
        $this->assortmentQuantities = [];

        $orderIds = $this->orders
            ->modelKeys();

        if ($orderIds === []) {
            return;
        }

        /*
        * Az adatbázis már SKU-nként összesíti a 212 000+
        * rendelési tételt.
        *
        * Így PHP-ba csak néhány ezer összesített sor érkezik,
        * nem több százezer Eloquent objektum.
        */
        $quantityRows = DB::table('order_items')
            ->select([
                'order_items.sku_id',
            ])
            ->selectRaw('SUM(order_items.quantity) AS total_quantity')
            ->selectRaw(
                'CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM item_assortments
                        WHERE item_assortments.assortment_sku_id = order_items.sku_id
                    )
                    THEN 1
                    ELSE 0
                END AS is_assortment'
            )
            ->whereIn('order_items.order_id', $orderIds)
            ->groupBy('order_items.sku_id')
            ->get();

        foreach ($quantityRows as $row) {
            $skuId = (int) $row->sku_id;
            $quantity = (int) $row->total_quantity;

            if ((int) $row->is_assortment === 1) {
                $this->assortmentQuantities[$skuId] = $quantity;
            } else {
                $this->pieceQuantities[$skuId] = $quantity;
            }
        }
    }

    protected function buildMatrixGroups(): void
    {
        $catalogGroupColumn = $this->catalogGroupNameColumn();

        $products = Product::query()
            ->with([
                'sizeRange.items.size',
                'colors.skus.size',
                'colors.itemAssortments.assortmentSku',
                'colors.itemAssortments.componentSku.size',
            ])
            ->where('season_id', $this->season->id)
            ->where('brand_id', $this->brand->id)
            ->where('order_sheet_type_id', $this->orderSheetType->id)
            ->where('active', true)
            ->whereNotNull($catalogGroupColumn)
            ->where($catalogGroupColumn, '!=', '')
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

        $this->matrixGroups = $groups->values()->all();
    }

    protected function calculateMatrixTotals(): void
    {
        /*
        * A darabos mennyiségekkel indulunk.
        *
        * A kulcs a SKU azonosítója, az érték pedig az összes
        * kiválasztott rendelésben szereplő darabszám.
        */
        $skuTotals = [];

        foreach ($this->pieceQuantities as $skuId => $quantity) {
            $skuTotals[(int) $skuId] = (int) $quantity;
        }

        /*
        * Az assortmentek tartalmát csak egyszer bontjuk szét
        * komponens SKU-kra.
        */
        foreach ($this->matrixGroups as $matrixGroup) {
            foreach ($matrixGroup['products'] as $product) {
                foreach ($product['colors'] as $color) {
                    foreach ($color['assortments'] as $assortment) {
                        $assortmentSkuId =
                            (int) $assortment['sku_id'];

                        $assortmentQuantity = (int) (
                            $this->assortmentQuantities[
                                $assortmentSkuId
                            ] ?? 0
                        );

                        if ($assortmentQuantity === 0) {
                            continue;
                        }

                        foreach (
                            $assortment['content'] as $componentSkuId => $componentQuantity
                        ) {
                            $componentSkuId =
                                (int) $componentSkuId;

                            $componentQuantity =
                                (int) $componentQuantity;

                            $skuTotals[$componentSkuId] = (
                                $skuTotals[$componentSkuId] ?? 0
                            ) + (
                                $assortmentQuantity
                                * $componentQuantity
                            );
                        }
                    }
                }
            }
        }

        $summaryQuantity = 0;
        $summaryWholesaleValue = 0.0;
        $summaryRetailValue = 0.0;

        /*
        * A kész SKU-összesítéseket beírjuk közvetlenül
        * a matrixGroups struktúrába.
        *
        * Így a Blade-ben már nincs számítás.
        */
        foreach ($this->matrixGroups as &$matrixGroup) {
            foreach ($matrixGroup['products'] as &$product) {
                foreach ($product['colors'] as &$color) {
                    $rowTotal = 0;

                    foreach ($color['sku_map'] as &$sku) {
                        $skuId = (int) $sku['id'];

                        $skuTotal = (int) (
                            $skuTotals[$skuId] ?? 0
                        );

                        $sku['total_quantity'] = $skuTotal;

                        $rowTotal += $skuTotal;
                    }

                    unset($sku);

                    $rowWholesaleValue = $rowTotal
                        * (float) ($product['price'] ?? 0);

                    $rowRetailValue = $rowTotal
                        * (float) ($product['retail_price'] ?? 0);

                    $color['row_total'] = $rowTotal;

                    $color['row_wholesale_value'] =
                        $rowWholesaleValue;

                    $color['row_retail_value'] =
                        $rowRetailValue;

                    $summaryQuantity += $rowTotal;

                    $summaryWholesaleValue +=
                        $rowWholesaleValue;

                    $summaryRetailValue +=
                        $rowRetailValue;
                }

                unset($color);
            }

            unset($product);
        }

        unset($matrixGroup);

        $this->summaryTotals = [
            'quantity' => $summaryQuantity,
            'wholesale_value' => $summaryWholesaleValue,
            'retail_value' => $summaryRetailValue,
        ];
    }

    protected function formatProduct(Product $product): array
    {
        $wholesalePrice = $this->getProductPrice($product->id, $this->priceListId);

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

    protected function getProductPrice(int $productId, ?int $priceListId): float
    {
        if (! $priceListId) {
            return 0;
        }

        return (float) (
            PriceListItem::query()
                ->where('price_list_id', $priceListId)
                ->where('product_id', $productId)
                ->where('season_id', $this->season->id)
                ->value('net_price') ?? 0
        );
    }

    public function getCurrencySymbol(): string
    {
        return $this->orders->first()?->priceList?->currency?->symbol ?? '';
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
            ->where('season_id', $this->season->id)
            ->where('brand_id', $this->brand->id)
            ->where('order_sheet_type_id', $this->orderSheetType->id)
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
        $export = new PartnerOrderSummaryMatrixExport($this->orders);

        return Excel::download(
            $export,
            $export->filename()
        );
    }

    public function render()
    {
        $startedAt = microtime(true);

        $this->setPartnerLocale();

        $view = view('livewire.partner.order-summary')
            ->layout('components.layouts.app');

        Log::info('OrderSummary timing: render prepared', [
            'seconds' => round(microtime(true) - $startedAt, 3),
        ]);

        return $view;
    }
}
