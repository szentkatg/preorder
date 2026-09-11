<?php

namespace App\Exports;

use App\Models\Order;
use App\Models\PriceListItem;
use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class PartnerOrderMatrixExport extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithEvents
{
    protected array $unavailableCells = [];

    protected array $editableCells = [];

    protected array $headerRows = [];

    protected array $groupTitleRows = [];

    protected array $blockRanges = [];

    protected array $totalQuantityCells = [];

    protected array $wholesaleValueCells = [];

    protected array $retailValueCells = [];

    protected array $headerRanges = [];

    protected array $blockLabelRanges = [];

    protected array $mergedBlockLabelRanges = [];

    protected array $hiddenColumnRanges = [];

    protected array $integerQuantityRanges = [];

    protected array $blockBoundaryRanges = [];

    protected array $sizeHeaderRanges = [];

    public function __construct(
        protected Order $order
    ) {}

    public function bindValue(Cell $cell, mixed $value): bool
    {
        // Csak a méretfejléceket kötjük explicit szövegként.
        // A mennyiségeknek és képleteknek numerikusnak kell maradniuk,
        // különben az Excel összesítések nem számolnak.
        if ($this->isSizeHeaderCell($cell) && ! str_starts_with((string) $value, '=')) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    protected function isSizeHeaderCell(Cell $cell): bool
    {
        $coordinate = $cell->getCoordinate();
        $column = preg_replace('/\d+/', '', $coordinate);
        $row = (int) preg_replace('/\D+/', '', $coordinate);

        if (! $column || ! $row) {
            return false;
        }

        $columnIndex = Coordinate::columnIndexFromString($column);

        foreach ($this->sizeHeaderRanges as $range) {
            if ((int) $range['row'] !== $row) {
                continue;
            }

            $startIndex = Coordinate::columnIndexFromString($range['start_column']);
            $endIndex = Coordinate::columnIndexFromString($range['end_column']);

            if ($columnIndex >= $startIndex && $columnIndex <= $endIndex) {
                return true;
            }
        }

        return false;
    }

    public function array(): array
    {
        $this->unavailableCells = [];
        $this->editableCells = [];
        $this->headerRows = [];
        $this->groupTitleRows = [];
        $this->blockRanges = [];
        $this->totalQuantityCells = [];
        $this->wholesaleValueCells = [];
        $this->retailValueCells = [];
        $this->headerRanges = [];
        $this->blockLabelRanges = [];
        $this->mergedBlockLabelRanges = [];
        $this->hiddenColumnRanges = [];
        $this->integerQuantityRanges = [];
        $this->blockBoundaryRanges = [];
        $this->sizeHeaderRanges = [];

        $rows = [];

        $order = $this->order->load([
            'partner',
            'partnerAddress.language',
            'season',
            'brand',
            'orderSheetType',
            'priceList.currency',
        ]);

        app()->setLocale(strtolower($order->partnerAddress?->language?->code ?? 'hu'));

        $partnerCode = $this->getPartnerCode($order->partner);
        $partnerName = $order->partner?->name ?? '';
        $addressCode = $this->getAddressCode($order->partnerAddress);
        $addressName = $order->partnerAddress?->name ?? '';
        $fullAddress = $this->getFullAddress($order->partnerAddress);

        $retailPriceListId = $order->priceList?->retail_price_list_id;
        $allowAssortmentOrder = $this->allowsAssortmentOrder($order->partnerAddress);

        $orderItems = $order
            ->items()
            ->pluck('quantity', 'sku_id')
            ->toArray();

        $rows[] = ['', __('partner.partner_code'), $partnerCode, '', __('partner.reference_number_short'), $order->reference_number];
        $rows[] = ['', __('partner.partner_name'), $partnerName];
        $rows[] = ['', __('partner.address_code'), $addressCode, $addressName];
        $rows[] = ['', __('partner.address'), $fullAddress];
        $rows[] = ['', __('partner.season'), $order->season?->name];
        $rows[] = ['', __('partner.brand'), $order->brand?->name];
        $rows[] = [
            '',
            __('partner.order_sheet_type'),
            $order->orderSheetType?->translate(
                'name',
                $this->translationLanguageId($order)
            ),
        ];
        $rows[] = [
            '',
            __('partner.price_list'),
            $order->priceList?->name_hu,
            __('partner.currency'),
            $order->priceList?->currency?->code,
        ];
        $rows[] = ['', __('partner.full_order')];
        $rows[] = ['', __('partner.total_quantity'), ''];
        $rows[] = ['', __('partner.wholesale_value'), ''];
        $rows[] = ['', __('partner.retail_value'), ''];

        $products = Product::query()
            ->with([
                'sizeRange.items.size',
                'colors.skus.size',
                'colors.itemAssortments.assortmentSku',
                'colors.itemAssortments.componentSku.size',
            ])
            ->where('season_id', $order->season_id)
            ->where('brand_id', $order->brand_id)
            ->where('order_sheet_type_id', $order->order_sheet_type_id)
            ->where('active', true)
            ->orderBy('catalog_group_sort')
            ->orderBy('catalog_sort')
            ->orderBy('model_code')
            ->get();

        $catalogGroups = $products->groupBy('catalog_group_name_hu');

        foreach ($catalogGroups as $catalogGroupName => $catalogProducts) {
            $matrixGroups = $catalogProducts
                ->groupBy(fn (Product $product) => $product->sizeRange?->matrix_group ?: 'EGYEB');

            foreach ($matrixGroups as $matrixGroupName => $matrixProducts) {
                $groupTitleRow = count($rows) + 1;

                $rows[] = [
                    '',
                    __('partner.catalog_group'),
                    $catalogGroupName,
                ];

                $this->groupTitleRows[] = $groupTitleRow;

                $sizes = $matrixProducts
                    ->pluck('sizeRange')
                    ->filter()
                    ->flatMap(fn ($sizeRange) => $sizeRange->items)
                    ->filter(fn ($item) => $item->size)
                    ->map(fn ($item) => [
                        'id' => (int) $item->size->id,
                        'code' => (string) $item->size->code,
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
                    ->values();

                $fixedHeaderColumns = [
                    'import_meta_json',
                    __('partner.catalog_group'),
                    __('partner.model'),
                    __('partner.name'),
                    __('partner.color'),
                    __('partner.page'),
                    __('partner.wholesale_price'),
                    __('partner.retail_price'),
                ];

                if ($allowAssortmentOrder) {
                    $fixedHeaderColumns[] = __('partner.assortment_order');
                }

                $fixedHeaderColumns[] = __('partner.total_quantity');
                $fixedHeaderColumns[] = __('partner.wholesale_value');
                $fixedHeaderColumns[] = __('partner.retail_value');

                $blockLabelRow = array_fill(0, count($fixedHeaderColumns), '');
                $headerRow = $fixedHeaderColumns;

                $manualStartColumn = count($headerRow) + 1;
                foreach ($sizes as $size) {
                    $blockLabelRow[] = '';
                    $headerRow[] = $this->formatSizeCode($size['code']);
                }
                $manualEndColumn = count($headerRow);

                $assortmentStartColumn = null;
                $assortmentEndColumn = null;

                if ($allowAssortmentOrder && $sizes->count() > 0) {
                    $assortmentStartColumn = count($headerRow) + 1;
                    foreach ($sizes as $size) {
                        $blockLabelRow[] = '';
                        $headerRow[] = $this->formatSizeCode($size['code']);
                    }
                    $assortmentEndColumn = count($headerRow);
                }

                $totalStartColumn = null;
                $totalEndColumn = null;

                if ($allowAssortmentOrder && $sizes->count() > 0) {
                    $totalStartColumn = count($headerRow) + 1;
                    foreach ($sizes as $size) {
                        $blockLabelRow[] = '';
                        $headerRow[] = $this->formatSizeCode($size['code']);
                    }
                    $totalEndColumn = count($headerRow);
                }

                $assortmentContentStartColumn = null;
                $assortmentContentEndColumn = null;

                if ($allowAssortmentOrder && $sizes->count() > 0) {
                    $assortmentContentStartColumn = count($headerRow) + 1;

                    foreach ($sizes as $size) {
                        $blockLabelRow[] = '';
                        $headerRow[] = $this->formatSizeCode($size['code']);
                    }

                    $assortmentContentEndColumn = count($headerRow);
                }

                if ($allowAssortmentOrder) {
                    $blockLabelRowNumber = count($rows) + 1;

                    if ($sizes->count() > 0) {
                        $blockLabelRow[$manualStartColumn - 1] = __('partner.piece_order');
                        $this->mergedBlockLabelRanges[] = [
                            'row' => $blockLabelRowNumber,
                            'start_column' => Coordinate::stringFromColumnIndex($manualStartColumn),
                            'end_column' => Coordinate::stringFromColumnIndex($manualEndColumn),
                        ];

                        if ($assortmentStartColumn && $assortmentEndColumn) {
                            $blockLabelRow[$assortmentStartColumn - 1] = __('partner.assortment_order');
                            $this->mergedBlockLabelRanges[] = [
                                'row' => $blockLabelRowNumber,
                                'start_column' => Coordinate::stringFromColumnIndex($assortmentStartColumn),
                                'end_column' => Coordinate::stringFromColumnIndex($assortmentEndColumn),
                            ];
                        }

                        if ($totalStartColumn && $totalEndColumn) {
                            $blockLabelRow[$totalStartColumn - 1] = __('partner.total_size_quantity');
                            $this->mergedBlockLabelRanges[] = [
                                'row' => $blockLabelRowNumber,
                                'start_column' => Coordinate::stringFromColumnIndex($totalStartColumn),
                                'end_column' => Coordinate::stringFromColumnIndex($totalEndColumn),
                            ];
                        }

                        if ($assortmentContentStartColumn && $assortmentContentEndColumn) {
                            $blockLabelRow[$assortmentContentStartColumn - 1] = __('partner.assortment_content');
                            $this->mergedBlockLabelRanges[] = [
                                'row' => $blockLabelRowNumber,
                                'start_column' => Coordinate::stringFromColumnIndex($assortmentContentStartColumn),
                                'end_column' => Coordinate::stringFromColumnIndex($assortmentContentEndColumn),
                            ];
                        }
                    }

                    $rows[] = $blockLabelRow;

                    $this->blockLabelRanges[] = [
                        'row' => $blockLabelRowNumber,
                        'end_column' => Coordinate::stringFromColumnIndex(count($headerRow)),
                    ];

                    foreach (array_filter([$manualEndColumn, $assortmentEndColumn, $totalEndColumn]) as $boundaryColumn) {
                        $this->blockBoundaryRanges[] = [
                            'column' => Coordinate::stringFromColumnIndex($boundaryColumn),
                            'start_row' => $blockLabelRowNumber,
                            'end_row' => null,
                        ];
                    }
                }

                $headerRowNumber = count($rows) + 1;
                $endColumn = Coordinate::stringFromColumnIndex(count($headerRow));

                $this->headerRanges[] = [
                    'row' => $headerRowNumber,
                    'end_column' => $endColumn,
                ];

                foreach ([
                    [$manualStartColumn, $manualEndColumn],
                    [$assortmentStartColumn, $assortmentEndColumn],
                    [$totalStartColumn, $totalEndColumn],
                    [$assortmentContentStartColumn, $assortmentContentEndColumn],
                ] as [$sizeStartColumn, $sizeEndColumn]) {
                    if ($sizeStartColumn && $sizeEndColumn) {
                        $this->sizeHeaderRanges[] = [
                            'row' => $headerRowNumber,
                            'start_column' => Coordinate::stringFromColumnIndex($sizeStartColumn),
                            'end_column' => Coordinate::stringFromColumnIndex($sizeEndColumn),
                        ];
                    }
                }

                if ($assortmentContentStartColumn && $assortmentContentEndColumn) {
                    $this->hiddenColumnRanges[] = [
                        'start' => Coordinate::stringFromColumnIndex($assortmentContentStartColumn),
                        'end' => Coordinate::stringFromColumnIndex($assortmentContentEndColumn),
                    ];
                }

                $rows[] = $headerRow;

                $blockStartRow = $allowAssortmentOrder ? $blockLabelRowNumber : $headerRowNumber;

                foreach ($matrixProducts as $product) {
                    $wholesalePrice = $this->getProductPrice(
                        $product->id,
                        $order->price_list_id,
                        $order->season_id
                    );

                    $retailPrice = $retailPriceListId
                        ? $this->getProductPrice(
                            $product->id,
                            $retailPriceListId,
                            $order->season_id
                        )
                        : 0;

                    foreach ($product->colors->where('active', true)->sortBy('sort_order') as $color) {
                        $rowNumber = count($rows) + 1;

                        $skuMap = $color->skus
                            ->where('active', true)
                            ->keyBy('size_id');

                        $assortmentSkuId = $color->itemAssortments
                            ->pluck('assortment_sku_id')
                            ->filter()
                            ->first();

                        $importMeta = [
                            'version' => 1,
                            'skus' => [],
                        ];

                        $row = [
                            '',
                            $catalogGroupName,
                            $product->model_code,
                            $product->name_hu,
                            $color->name_hu,
                            $product->catalog_page ?? '',
                            $wholesalePrice,
                            $retailPrice,
                        ];

                        $assortmentColumnLetter = null;

                        if ($allowAssortmentOrder) {
                            $assortmentColumn = count($row) + 1;
                            $assortmentColumnLetter = Coordinate::stringFromColumnIndex($assortmentColumn);

                            $row[] = $assortmentSkuId
                                ? ($orderItems[$assortmentSkuId] ?? null)
                                : null;

                            if ($assortmentSkuId) {
                                $this->editableCells[] = "{$assortmentColumnLetter}{$rowNumber}";
                                $importMeta['skus'][(string) $assortmentColumn] = (int) $assortmentSkuId;
                            } else {
                                $this->unavailableCells[] = "{$assortmentColumnLetter}{$rowNumber}";
                            }
                        }

                        $totalQuantityColumn = count($row) + 1;
                        $totalQuantityColumnLetter = Coordinate::stringFromColumnIndex($totalQuantityColumn);

                        $wholesaleValueColumn = count($row) + 2;
                        $wholesaleValueColumnLetter = Coordinate::stringFromColumnIndex($wholesaleValueColumn);

                        $retailValueColumn = count($row) + 3;
                        $retailValueColumnLetter = Coordinate::stringFromColumnIndex($retailValueColumn);

                        $row[] = null;
                        $row[] = null;
                        $row[] = null;

                        $manualSizeStartColumn = count($row) + 1;
                        $manualSizeCells = [];
                        $assortmentSizeCells = [];
                        $totalSizeCells = [];
                        $assortmentContentCells = [];

                        foreach ($sizes as $size) {
                            $columnIndex = count($row) + 1;
                            $columnLetter = Coordinate::stringFromColumnIndex($columnIndex);

                            $sku = $skuMap->get($size['id']);

                            if ($sku) {
                                $row[] = $orderItems[$sku->id] ?? null;
                                $this->editableCells[] = "{$columnLetter}{$rowNumber}";
                                $manualSizeCells[$size['id']] = "{$columnLetter}{$rowNumber}";
                                $importMeta['skus'][(string) $columnIndex] = (int) $sku->id;
                            } else {
                                $row[] = '-';
                                $this->unavailableCells[] = "{$columnLetter}{$rowNumber}";
                                $manualSizeCells[$size['id']] = null;
                            }
                        }

                        if ($allowAssortmentOrder) {
                            foreach ($sizes as $sizeIndex => $size) {
                                $columnIndex = count($row) + 1;
                                $columnLetter = Coordinate::stringFromColumnIndex($columnIndex);
                                $assortmentSizeCells[$size['id']] = "{$columnLetter}{$rowNumber}";

                                if ($assortmentSkuId && $assortmentColumnLetter && $assortmentContentStartColumn) {
                                    $contentColumnLetter = Coordinate::stringFromColumnIndex($assortmentContentStartColumn + $sizeIndex);
                                    $row[] = '=IF('.$assortmentColumnLetter.$rowNumber.'="-",0,'.$assortmentColumnLetter.$rowNumber.')*'.$contentColumnLetter.$rowNumber;
                                } else {
                                    $row[] = 0;
                                }
                            }
                        }

                        if ($allowAssortmentOrder) {
                            foreach ($sizes as $size) {
                                $columnIndex = count($row) + 1;
                                $columnLetter = Coordinate::stringFromColumnIndex($columnIndex);
                                $totalSizeCells[$size['id']] = "{$columnLetter}{$rowNumber}";

                                $manualCell = $manualSizeCells[$size['id']] ?? null;
                                $assortmentCell = $assortmentSizeCells[$size['id']] ?? null;

                                if ($manualCell && $assortmentCell) {
                                    $row[] = '=IF('.$manualCell.'="-",0,'.$manualCell.')+'.$assortmentCell;
                                } elseif ($manualCell) {
                                    $row[] = '=IF('.$manualCell.'="-",0,'.$manualCell.')';
                                } else {
                                    $row[] = 0;
                                }
                            }
                        } else {
                            $totalSizeCells = array_filter($manualSizeCells);
                        }

                        if ($allowAssortmentOrder) {
                            foreach ($sizes as $size) {
                                $sku = $skuMap->get($size['id']);
                                $row[] = $this->getAssortmentSizeQuantity(
                                    $color->itemAssortments,
                                    $assortmentSkuId,
                                    $sku?->id,
                                    (int) $size['id']
                                );
                            }
                        }

                        $totalFormula = $totalSizeCells
                            ? '=SUM('.implode(',', $totalSizeCells).')'
                            : 0;

                        $row[$totalQuantityColumn - 1] = $totalFormula;
                        $wholesalePriceColumnLetter = Coordinate::stringFromColumnIndex(7);
                        $retailPriceColumnLetter = Coordinate::stringFromColumnIndex(8);

                        $row[$wholesaleValueColumn - 1] = "={$totalQuantityColumnLetter}{$rowNumber}*{$wholesalePriceColumnLetter}{$rowNumber}";
                        $row[$retailValueColumn - 1] = "={$totalQuantityColumnLetter}{$rowNumber}*{$retailPriceColumnLetter}{$rowNumber}";

                        $this->totalQuantityCells[] = "{$totalQuantityColumnLetter}{$rowNumber}";
                        $this->wholesaleValueCells[] = "{$wholesaleValueColumnLetter}{$rowNumber}";
                        $this->retailValueCells[] = "{$retailValueColumnLetter}{$rowNumber}";

                        $row[0] = json_encode($importMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        $rows[] = $row;
                    }
                }

                $blockEndRow = count($rows);
                $blockEndColumn = Coordinate::stringFromColumnIndex(count($headerRow));

                if ($blockEndRow >= $headerRowNumber + 1) {
                    if ($allowAssortmentOrder) {
                        $this->integerQuantityRanges[] = Coordinate::stringFromColumnIndex(9).($headerRowNumber + 1).':'.Coordinate::stringFromColumnIndex(9).$blockEndRow;
                    }

                    $this->integerQuantityRanges[] = Coordinate::stringFromColumnIndex($manualSizeStartColumn).($headerRowNumber + 1).':'.Coordinate::stringFromColumnIndex($manualEndColumn).$blockEndRow;

                    if ($assortmentStartColumn && $assortmentEndColumn) {
                        $this->integerQuantityRanges[] = Coordinate::stringFromColumnIndex($assortmentStartColumn).($headerRowNumber + 1).':'.Coordinate::stringFromColumnIndex($assortmentEndColumn).$blockEndRow;
                    }

                    if ($totalStartColumn && $totalEndColumn) {
                        $this->integerQuantityRanges[] = Coordinate::stringFromColumnIndex($totalStartColumn).($headerRowNumber + 1).':'.Coordinate::stringFromColumnIndex($totalEndColumn).$blockEndRow;
                    }
                }

                foreach ($this->blockBoundaryRanges as $index => $boundaryRange) {
                    if ($boundaryRange['end_row'] === null && $boundaryRange['start_row'] === $blockStartRow) {
                        $this->blockBoundaryRanges[$index]['end_row'] = $blockEndRow;
                    }
                }

                $this->blockRanges[] = [
                    'start' => $blockStartRow,
                    'end' => $blockEndRow,
                    'end_column' => $blockEndColumn,
                ];
            }
        }

        $rows[9][2] = $this->totalQuantityCells
            ? '='.implode('+', $this->totalQuantityCells)
            : 0;

        $rows[10][2] = $this->wholesaleValueCells
            ? '='.implode('+', $this->wholesaleValueCells)
            : 0;

        $rows[11][2] = $this->retailValueCells
            ? '='.implode('+', $this->retailValueCells)
            : 0;

        return $rows;
    }

    protected function formatSizeCode(mixed $code): string
    {
        $code = trim((string) $code);

        if (is_numeric($code) && (float) $code == (int) $code) {
            return (string) (int) $code;
        }

        return $code;
    }

    protected function allowsAssortmentOrder($partnerAddress): bool
    {
        return (bool) (
            $partnerAddress?->allow_assortment_ordering
            ?? $partnerAddress?->assortment_ordering_enabled
            ?? $partnerAddress?->can_order_assortments
            ?? false
        );
    }

    protected function getAssortmentSizeQuantity($itemAssortments, ?int $assortmentSkuId, ?int $skuId, int $sizeId): int
    {
        if (! $assortmentSkuId) {
            return 0;
        }

        return (int) $itemAssortments
            ->where('assortment_sku_id', $assortmentSkuId)
            ->filter(function ($item) use ($skuId, $sizeId) {
                $itemSkuId = (int) (
                    data_get($item, 'component_sku_id')
                    ?? data_get($item, 'sku_id')
                    ?? data_get($item, 'item_sku_id')
                    ?? data_get($item, 'content_sku_id')
                    ?? data_get($item, 'product_sku_id')
                    ?? data_get($item, 'componentSku.id')
                    ?? data_get($item, 'sku.id')
                    ?? data_get($item, 'itemSku.id')
                    ?? data_get($item, 'contentSku.id')
                    ?? 0
                );

                if ($skuId && $itemSkuId === (int) $skuId) {
                    return true;
                }

                $itemSizeId = (int) (
                    data_get($item, 'size_id')
                    ?? data_get($item, 'componentSku.size_id')
                    ?? data_get($item, 'sku.size_id')
                    ?? data_get($item, 'itemSku.size_id')
                    ?? data_get($item, 'contentSku.size_id')
                    ?? 0
                );

                return $itemSizeId === $sizeId;
            })
            ->sum('quantity');
    }

    protected function getProductPrice(int $productId, ?int $priceListId, ?int $seasonId): float
    {
        if (! $priceListId || ! $seasonId) {
            return 0;
        }

        return (float) (
            PriceListItem::query()
                ->where('price_list_id', $priceListId)
                ->where('season_id', $seasonId)
                ->where('product_id', $productId)
                ->value('net_price') ?? 0
        );
    }

    protected function getPartnerCode($partner): ?string
    {
        return $partner?->code
            ?? $partner?->erp_partner_code
            ?? $partner?->partner_code
            ?? null;
    }

    protected function getAddressCode($address): ?string
    {
        return $address?->code
            ?? $address?->addrid
            ?? $address?->address_code
            ?? null;
    }

    protected function getFullAddress($address): string
    {
        $country = $address?->country ?? '';
        $zip = $address?->zip ?? $address?->postal_code ?? '';
        $city = $address?->city ?? '';
        $street = $address?->street ?? $address?->address ?? '';

        return trim($country.'-'.$zip.' '.$city.', '.$street, ' -,');
    }

    public function filename(): string
    {
        $order = $this->order->load(['partner', 'partnerAddress', 'brand', 'orderSheetType']);

        $brandName = $this->sanitizeFilenamePart(
            $order->brand?->name ?? 'marka'
        );

        $orderSheetTypeName = $this->sanitizeFilenamePart(
            $order->orderSheetType?->translate(
                'name',
                $this->translationLanguageId($order)
            ) ?: 'rendelolap'
        );

        $partnerCode = $this->sanitizeFilenamePart(
            $this->getPartnerCode($order->partner) ?? 'partner'
        );

        $partnerName = $this->sanitizeFilenamePart(
            $order->partner?->name ?? 'nev'
        );

        $addressCode = $this->sanitizeFilenamePart(
            $this->getAddressCode($order->partnerAddress) ?? 'cim'
        );

        $addressName = $this->sanitizeFilenamePart(
            $order->partnerAddress?->name ?? 'cimnev'
        );

        $referenceNumber = $this->sanitizeFilenamePart(
            $order->reference_number ?? 'hivatkozas'
        );

        return "{$brandName}_{$orderSheetTypeName}_{$referenceNumber}_{$partnerCode}_{$partnerName}_{$addressCode}_{$addressName}.xlsx";
    }

    protected function sanitizeFilenamePart(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('#[\\/:*?"<>|]+#u', '', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = str_replace(' ', '_', trim($value));
        $value = trim($value, '_');

        return $value ?: 'adat';
    }

    protected function translationLanguageId(Order $order): ?int
    {
        $languageId = $order->partnerAddress?->language_id;

        return $languageId ? (int) $languageId : null;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->setShowGridlines(false);
                $sheet->getStyle('B10:K2000')->getProtection()->setLocked(Protection::PROTECTION_PROTECTED);

                foreach (['B1:C4', 'E1:F1'] as $range) {
                    $sheet->getStyle($range)->getFont()->setBold(true);
                }

                $sheet->getStyle('B10:C12')
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN);

                $sheet->getStyle('C10:C12')
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFFFFFCC');

                $sheet->getStyle('C10')
                    ->getNumberFormat()
                    ->setFormatCode('#,##0');

                $sheet->getStyle('C11:C12')
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');

                foreach ($this->groupTitleRows as $row) {
                    $sheet->getStyle("B{$row}:C{$row}")
                        ->getFont()
                        ->setBold(true);

                    $sheet->getStyle("B{$row}:C{$row}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFD9EAF7');
                }

                foreach ($this->blockLabelRanges as $range) {
                    $row = $range['row'];
                    $endColumn = $range['end_column'];

                    $sheet->getStyle("A{$row}:{$endColumn}{$row}")
                        ->getFont()
                        ->setBold(true);

                    $sheet->getStyle("A{$row}:{$endColumn}{$row}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFD9EAF7');

                    $sheet->getStyle("A{$row}:{$endColumn}{$row}")
                        ->getAlignment()
                        ->setWrapText(true)
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                foreach ($this->mergedBlockLabelRanges as $range) {
                    $sheet->mergeCells("{$range['start_column']}{$range['row']}:{$range['end_column']}{$range['row']}");
                    $sheet->getStyle("{$range['start_column']}{$range['row']}:{$range['end_column']}{$range['row']}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                        ->setVertical(Alignment::VERTICAL_CENTER);
                }

                foreach ($this->headerRanges as $range) {
                    $row = $range['row'];
                    $endColumn = $range['end_column'];

                    $sheet->getStyle("A{$row}:{$endColumn}{$row}")
                        ->getFont()
                        ->setBold(true);

                    $sheet->getStyle("A{$row}:{$endColumn}{$row}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFE5E7EB');

                    $sheet->getStyle("A{$row}:{$endColumn}{$row}")
                        ->getAlignment()
                        ->setWrapText(true)
                        ->setVertical(Alignment::VERTICAL_CENTER);

                    $sheet->getRowDimension($row)->setRowHeight(30);

                    $sheet->getStyle("A{$row}:{$endColumn}{$row}")
                        ->getNumberFormat()
                        ->setFormatCode(NumberFormat::FORMAT_GENERAL);
                }

                foreach ($this->blockRanges as $range) {
                    $sheet->getStyle("A{$range['start']}:{$range['end_column']}{$range['end']}")
                        ->getBorders()
                        ->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN);

                    $sheet->getStyle("K{$range['start']}:M{$range['end']}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00');

                    $sheet->getStyle("J{$range['start']}:J{$range['end']}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0');

                    $sheet->getStyle("G{$range['start']}:H{$range['end']}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00');
                }

                foreach ($this->integerQuantityRanges as $range) {
                    $sheet->getStyle($range)
                        ->getNumberFormat()
                        ->setFormatCode('#,##0');
                }

                // A méretfejléceket a legvégén állítjuk szöveg formátumra,
                // mert a blokk szintű számformátumok különben felülírhatják.
                foreach ($this->sizeHeaderRanges as $range) {
                    $sheet->getStyle("{$range['start_column']}{$range['row']}:{$range['end_column']}{$range['row']}")
                        ->getNumberFormat()
                        ->setFormatCode(NumberFormat::FORMAT_TEXT);
                }

                foreach ($this->totalQuantityCells as $cell) {
                    $sheet->getStyle($cell)
                        ->getNumberFormat()
                        ->setFormatCode('#,##0');

                    $sheet->getStyle($cell)
                        ->getFill()
                        ->setFillType(
                            Fill::FILL_SOLID
                        )
                        ->getStartColor()
                        ->setARGB('FFD9D9D9');
                }

                foreach (array_merge($this->wholesaleValueCells, $this->retailValueCells) as $cell) {
                    $sheet->getStyle($cell)
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00');
                }

                foreach ($this->blockBoundaryRanges as $range) {
                    if (! $range['end_row']) {
                        continue;
                    }

                    $rightBorder = $sheet->getStyle("{$range['column']}{$range['start_row']}:{$range['column']}{$range['end_row']}")
                        ->getBorders()
                        ->getRight();

                    $rightBorder->setBorderStyle(Border::BORDER_THICK);
                    $rightBorder->getColor()->setARGB('FF000000');
                }

                foreach ($this->unavailableCells as $cell) {
                    $sheet->getStyle($cell)
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFD9D9D9');
                }

                $sheet->getProtection()->setSheet(true);
                $sheet->getProtection()->setFormatColumns(true);

                foreach ($this->editableCells as $cell) {
                    $sheet->getStyle($cell)
                        ->getProtection()
                        ->setLocked(Protection::PROTECTION_UNPROTECTED);
                }

                foreach ($this->hiddenColumnRanges as $range) {
                    for (
                        $column = Coordinate::columnIndexFromString($range['start']);
                        $column <= Coordinate::columnIndexFromString($range['end']);
                        $column++
                    ) {
                        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setVisible(true);
                    }
                }
                $sheet->getStyle('C1:D4')->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
                $sheet->getColumnDimension('A')->setVisible(false);
                $sheet->getColumnDimension('A')->setWidth(12);
                $sheet->getColumnDimension('B')->setWidth(20);
                $sheet->getColumnDimension('C')->setWidth(15);
                $sheet->getColumnDimension('G')->setWidth(15);
                $sheet->getColumnDimension('H')->setWidth(15);
                $sheet->getColumnDimension('I')->setWidth(10);
                $sheet->getColumnDimension('J')->setWidth(15);
                $sheet->getColumnDimension('K')->setWidth(15);

                $startColumnIndex = Coordinate::columnIndexFromString('L');
                $endColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());

                for ($columnIndex = $startColumnIndex; $columnIndex <= $endColumnIndex; $columnIndex++) {
                    $column = Coordinate::stringFromColumnIndex($columnIndex);

                    $sheet->getColumnDimension($column)->setAutoSize(true);
                }

                /*                $sheet->getColumnDimension('D')->setWidth(16);
                                $sheet->getColumnDimension('E')->setWidth(30);
                                $sheet->getColumnDimension('F')->setWidth(18);
                                $sheet->getColumnDimension('L')->setWidth(16);
                                $sheet->getColumnDimension('M')->setWidth(16);
                */
                $sheet->freezePane('F13');
                $sheet->setSelectedCell('F13');
                $sheet->getProtection()->setFormatColumns(true);
                /*
                |--------------------------------------------------------------------------
                | Nyomtatási beállítások
                |--------------------------------------------------------------------------
                */

                $pageSetup = $sheet->getPageSetup();

                $pageSetup->setOrientation(
                    PageSetup::ORIENTATION_LANDSCAPE
                );

                $pageSetup->setFitToWidth(1);
                $pageSetup->setFitToHeight(0);

                $margins = $sheet->getPageMargins();

                /*
                 * PhpSpreadsheet hüvelykben tárolja a margókat.
                 * 0,5 cm = 0,19685 inch
                 */
                $margins->setTop(0.19685);
                $margins->setBottom(0.19685);
                $margins->setLeft(0.19685);
                $margins->setRight(0.19685);

                $sheet->getPageSetup()->setHorizontalCentered(true);

            },
        ];
    }
}
