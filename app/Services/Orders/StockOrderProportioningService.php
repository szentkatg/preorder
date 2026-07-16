<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sku;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockOrderProportioningService
{
    /**
     * Elkészíti a számítási előnézetet.
     *
     * A groups rész a képernyős megjelenítéshez használható.
     * A write_payload kizárólag a későbbi mentéshez szükséges,
     * tömör adatokat tartalmazza.
     *
     * @return array{
     *     scope: array<string, int>,
     *     summary: array<string, int>,
     *     groups: array<int, array<string, mixed>>,
     *     write_payload: array{
     *         scope: array<string, int>,
     *         summary: array<string, int>,
     *         items: array<int, array{sku_id: int, quantity: int}>
     *     }
     * }
     */
    public function preview(
        int $ratioOrderId,
        int $stockOrderId
    ): array {
        [$ratioOrder, $stockOrder] = $this->loadAndValidateOrders(
            $ratioOrderId,
            $stockOrderId
        );

        $partnerOrderIds = Order::query()
            ->where('season_id', $stockOrder->season_id)
            ->where('brand_id', $stockOrder->brand_id)
            ->where(
                'order_sheet_type_id',
                $stockOrder->order_sheet_type_id
            )
            ->whereNotIn('id', [
                $ratioOrder->id,
                $stockOrder->id,
            ])
            ->pluck('id')
            ->map(
                fn (mixed $orderId): int => (int) $orderId
            )
            ->all();

        $partnerQuantities =
            $this->aggregateExpandedQuantitiesForOrderIds(
                $partnerOrderIds
            );

        $ratioQuantities =
            $this->aggregateExpandedQuantitiesForOrderIds([
                $ratioOrder->id,
            ]);

        $stockPlanQuantities =
            $this->aggregateExpandedQuantitiesForOrderIds([
                $stockOrder->id,
            ]);

        $skuMetadata = $this->collectSkuMetadata(
            $partnerQuantities,
            $ratioQuantities,
            $stockPlanQuantities
        );

        $groupedSkuIds = $this->groupSkuIdsByProductAndColor(
            $skuMetadata
        );

        $resultGroups = [];
        $writeQuantitiesBySku = [];

        $totalSizeCount = 0;
        $totalPartnerQuantity = 0;
        $totalNewStockQuantity = 0;
        $totalFinalQuantity = 0;
        $negativeCorrectionCount = 0;

        foreach ($groupedSkuIds as $groupKey => $skuIds) {
            $firstSkuId = $skuIds->first();

            if ($firstSkuId === null) {
                continue;
            }

            $firstMetadata = $skuMetadata[$firstSkuId] ?? null;

            if (! $firstMetadata) {
                continue;
            }

            $plannedStockTotal = $skuIds->sum(
                fn (int $skuId): int =>
                    (int) ($stockPlanQuantities[$skuId] ?? 0)
            );

            $ratioSum = $skuIds->sum(
                fn (int $skuId): int =>
                    max(
                        0,
                        (int) ($ratioQuantities[$skuId] ?? 0)
                    )
            );

            $groupItems = [];

            $partnerGroupTotal = 0;
            $newStockGroupTotal = 0;
            $finalGroupTotal = 0;
            $groupNegativeCorrectionCount = 0;

            foreach ($skuIds as $skuId) {
                $metadata = $skuMetadata[$skuId] ?? null;

                if (! $metadata) {
                    continue;
                }

                $partnerQuantity = (int) (
                    $partnerQuantities[$skuId] ?? 0
                );

                $ratio = max(
                    0,
                    (int) ($ratioQuantities[$skuId] ?? 0)
                );

                if ($ratioSum === 0) {
                    /*
                     * Nulla arányösszeg esetén az adott termék-szín
                     * végső célmennyisége nulla.
                     */
                    $rawAllocatedStock = 0.0;
                    $roundedFinalQuantity = 0;
                } else {
                    $rawAllocatedStock =
                        $plannedStockTotal
                        * $ratio
                        / $ratioSum;

                    $rawCombinedQuantity =
                        $partnerQuantity
                        + $rawAllocatedStock;

                    $roundedFinalQuantity =
                        $this->roundToNearestFive(
                            $rawCombinedQuantity
                        );
                }

                $newStockQuantity =
                    $roundedFinalQuantity
                    - $partnerQuantity;

                /*
                 * A mentési térképben minden méret-SKU pontosan
                 * egyszer szerepel.
                 */
                $writeQuantitiesBySku[$skuId] =
                    $newStockQuantity;

                if ($newStockQuantity < 0) {
                    $negativeCorrectionCount++;
                    $groupNegativeCorrectionCount++;
                }

                $groupItems[] = [
                    'sku_id' => $skuId,
                    'sku_code' => $metadata['sku_code'],
                    'size_id' => $metadata['size_id'],
                    'size_code' => $metadata['size_code'],
                    'size_sort_order' =>
                        $metadata['size_sort_order'],

                    'partner_quantity' => $partnerQuantity,
                    'ratio' => $ratio,
                    'ratio_sum' => $ratioSum,
                    'raw_allocated_stock' => round(
                        $rawAllocatedStock,
                        4
                    ),
                    'new_stock_quantity' =>
                        $newStockQuantity,
                    'final_quantity' =>
                        $roundedFinalQuantity,
                ];

                $partnerGroupTotal += $partnerQuantity;
                $newStockGroupTotal += $newStockQuantity;
                $finalGroupTotal += $roundedFinalQuantity;

                $totalSizeCount++;
                $totalPartnerQuantity += $partnerQuantity;
                $totalNewStockQuantity += $newStockQuantity;
                $totalFinalQuantity += $roundedFinalQuantity;
            }

            $resultGroups[] = [
                'group_key' => $groupKey,

                'product_id' =>
                    $firstMetadata['product_id'],
                'model_code' =>
                    $firstMetadata['model_code'],
                'product_name' =>
                    $firstMetadata['product_name'],

                'color_id' =>
                    $firstMetadata['color_id'],
                'color_code' =>
                    $firstMetadata['color_code'],
                'color_name' =>
                    $firstMetadata['color_name'],

                'planned_stock_total' =>
                    $plannedStockTotal,
                'ratio_sum' => $ratioSum,

                'partner_quantity' =>
                    $partnerGroupTotal,
                'new_stock_quantity' =>
                    $newStockGroupTotal,
                'final_quantity' =>
                    $finalGroupTotal,

                'negative_correction_count' =>
                    $groupNegativeCorrectionCount,

                'items' => $groupItems,
            ];
        }

        usort(
            $resultGroups,
            fn (array $left, array $right): int =>
                [
                    $left['model_code'],
                    $left['color_code'],
                ] <=> [
                    $right['model_code'],
                    $right['color_code'],
                ]
        );

        $scope = [
            'season_id' =>
                (int) $stockOrder->season_id,
            'brand_id' =>
                (int) $stockOrder->brand_id,
            'order_sheet_type_id' =>
                (int) $stockOrder->order_sheet_type_id,
            'ratio_order_id' =>
                (int) $ratioOrder->id,
            'stock_order_id' =>
                (int) $stockOrder->id,
            'partner_order_count' =>
                count($partnerOrderIds),
        ];

        $summary = [
            'group_count' =>
                count($resultGroups),
            'size_count' =>
                $totalSizeCount,
            'partner_quantity' =>
                $totalPartnerQuantity,
            'original_stock_quantity' =>
                array_sum($stockPlanQuantities),
            'new_stock_quantity' =>
                $totalNewStockQuantity,
            'final_quantity' =>
                $totalFinalQuantity,
            'negative_correction_count' =>
                $negativeCorrectionCount,
        ];

        $writeItems = [];

        foreach ($writeQuantitiesBySku as $skuId => $quantity) {
            $writeItems[] = [
                'sku_id' => (int) $skuId,
                'quantity' => (int) $quantity,
            ];
        }

        return [
            'scope' => $scope,
            'summary' => $summary,
            'groups' => $resultGroups,

            /*
             * Ezt kell külön cache-elni a mentéshez.
             * Nem tartalmaz termékneveket, színneveket,
             * arányszámítási részleteket vagy méretmetaadatokat.
             */
            'write_payload' => [
                'scope' => $scope,
                'summary' => $summary,
                'items' => $writeItems,
            ],
        ];
    }

    /**
     * A korábban kiszámított, tömör mentési csomagból
     * felülírja a készletrendelést.
     *
     * Nem hívja meg újra a preview() metódust.
     *
     * @param array<string, mixed> $writePayload
     *
     * @return array{
     *     scope: array<string, int>,
     *     summary: array<string, int>,
     *     applied: bool
     * }
     */
    public function apply(
        array $writePayload,
        int $ratioOrderId,
        int $stockOrderId
    ): array {
        $scope = $writePayload['scope'] ?? [];
        $summary = $writePayload['summary'] ?? [];
        $items = $writePayload['items'] ?? [];

        if (! is_array($scope) || ! is_array($items)) {
            throw new InvalidArgumentException(
                'A mentési előnézet formátuma érvénytelen. '
                . 'Készíts új számítást.'
            );
        }

        if (
            (int) ($scope['ratio_order_id'] ?? 0)
                !== $ratioOrderId
            || (int) ($scope['stock_order_id'] ?? 0)
                !== $stockOrderId
        ) {
            throw new InvalidArgumentException(
                'Az előnézet nem a jelenleg kiválasztott '
                . 'rendelésekhez tartozik. Készíts új számítást.'
            );
        }

        [, $stockOrder] = $this->loadAndValidateOrders(
            $ratioOrderId,
            $stockOrderId
        );

        if (
            (int) ($scope['season_id'] ?? 0)
                !== (int) $stockOrder->season_id
            || (int) ($scope['brand_id'] ?? 0)
                !== (int) $stockOrder->brand_id
            || (int) (
                $scope['order_sheet_type_id'] ?? 0
            ) !== (int) $stockOrder->order_sheet_type_id
        ) {
            throw new InvalidArgumentException(
                'A rendelés adatai megváltoztak az előnézet '
                . 'elkészítése óta. Készíts új számítást.'
            );
        }

        /*
         * SKU-nként egyetlen végleges mennyiséget tartunk meg.
         */
        $quantitiesBySku = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $skuId = (int) ($item['sku_id'] ?? 0);

            if ($skuId <= 0) {
                continue;
            }

            $quantitiesBySku[$skuId] = (int) (
                $item['quantity'] ?? 0
            );
        }

        if ($quantitiesBySku === []) {
            throw new InvalidArgumentException(
                'Az előnézet nem tartalmaz menthető SKU-adatokat. '
                . 'Készíts új számítást.'
            );
        }

        DB::transaction(function () use (
            $stockOrderId,
            $quantitiesBySku
        ): void {
            /*
             * A rendelés zárolva marad a tranzakció végéig.
             */
            $lockedStockOrder = Order::query()
                ->whereKey($stockOrderId)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Az egységárakat törlés előtt eltesszük.
             */
            $existingPrices = OrderItem::query()
                ->where(
                    'order_id',
                    $lockedStockOrder->id
                )
                ->pluck('unit_price', 'sku_id');

            OrderItem::query()
                ->where(
                    'order_id',
                    $lockedStockOrder->id
                )
                ->delete();

            $now = now();
            $rows = [];

            foreach ($quantitiesBySku as $skuId => $quantity) {
                if ($quantity === 0) {
                    continue;
                }

                $unitPrice = (float) (
                    $existingPrices[$skuId] ?? 0
                );

                $rows[] = [
                    'order_id' =>
                        $lockedStockOrder->id,
                    'sku_id' =>
                        $skuId,
                    'quantity' =>
                        $quantity,
                    'unit_price' =>
                        $unitPrice,
                    'line_total' =>
                        $quantity * $unitPrice,
                    'created_at' =>
                        $now,
                    'updated_at' =>
                        $now,
                ];
            }

            /*
             * Az insert csak a teljes sorlista elkészülte után indul.
             */
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('order_items')->insert($chunk);
            }
        });

        return [
            'scope' => $scope,
            'summary' => is_array($summary)
                ? $summary
                : [],
            'applied' => true,
        ];
    }

    /**
     * Betölti és ellenőrzi a két speciális rendelést.
     *
     * @return array{0: Order, 1: Order}
     */
    protected function loadAndValidateOrders(
        int $ratioOrderId,
        int $stockOrderId
    ): array {
        if ($ratioOrderId <= 0 || $stockOrderId <= 0) {
            throw new InvalidArgumentException(
                'Az arányrendelést és a készletrendelést '
                . 'is ki kell választani.'
            );
        }

        if ($ratioOrderId === $stockOrderId) {
            throw new InvalidArgumentException(
                'Az arányrendelés és a készletrendelés '
                . 'nem lehet ugyanaz.'
            );
        }

        $orders = Order::query()
            ->whereIn('id', [
                $ratioOrderId,
                $stockOrderId,
            ])
            ->get()
            ->keyBy('id');

        $ratioOrder = $orders->get($ratioOrderId);
        $stockOrder = $orders->get($stockOrderId);

        if (! $ratioOrder) {
            throw new InvalidArgumentException(
                "Az arányrendelés nem található: #{$ratioOrderId}"
            );
        }

        if (! $stockOrder) {
            throw new InvalidArgumentException(
                "A készletrendelés nem található: #{$stockOrderId}"
            );
        }

        foreach (
            [
                'season_id' => 'szezon',
                'brand_id' => 'márka',
                'order_sheet_type_id' =>
                    'rendelőlap-típus',
            ] as $field => $label
        ) {
            if (
                (int) $ratioOrder->{$field}
                !== (int) $stockOrder->{$field}
            ) {
                throw new InvalidArgumentException(
                    "A két rendelés {$label} értéke nem azonos."
                );
            }
        }

        return [$ratioOrder, $stockOrder];
    }

    /**
     * A rendelések tételeit méret-SKU szintre összesíti.
     *
     * A normál SKU-k közvetlenül kerülnek az eredménybe.
     * A gyűjtő SKU-k az item_assortments összetétele
     * alapján bomlanak méret-SKU-kra.
     *
     * @param array<int, int> $orderIds
     *
     * @return array<int, int>
     */
    protected function aggregateExpandedQuantitiesForOrderIds(
        array $orderIds
    ): array {
        if ($orderIds === []) {
            return [];
        }

        $directQuantities = DB::table('order_items')
            ->whereIn(
                'order_items.order_id',
                $orderIds
            )
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('item_assortments')
                    ->whereColumn(
                        'item_assortments.assortment_sku_id',
                        'order_items.sku_id'
                    );
            })
            ->groupBy('order_items.sku_id')
            ->selectRaw(
                'order_items.sku_id, '
                . 'SUM(order_items.quantity) AS quantity'
            )
            ->pluck('quantity', 'sku_id');

        $assortmentQuantities = DB::table('order_items')
            ->join(
                'item_assortments',
                'item_assortments.assortment_sku_id',
                '=',
                'order_items.sku_id'
            )
            ->whereIn(
                'order_items.order_id',
                $orderIds
            )
            ->groupBy(
                'item_assortments.component_sku_id'
            )
            ->selectRaw(
                'item_assortments.component_sku_id AS sku_id, '
                . 'SUM('
                . 'order_items.quantity '
                . '* item_assortments.quantity'
                . ') AS quantity'
            )
            ->pluck('quantity', 'sku_id');

        $result = [];

        foreach ($directQuantities as $skuId => $quantity) {
            $skuId = (int) $skuId;

            $result[$skuId] =
                ($result[$skuId] ?? 0)
                + (int) $quantity;
        }

        foreach (
            $assortmentQuantities
            as $skuId => $quantity
        ) {
            $skuId = (int) $skuId;

            $result[$skuId] =
                ($result[$skuId] ?? 0)
                + (int) $quantity;
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function collectSkuMetadata(
        array ...$quantityMaps
    ): array {
        $skuIds = collect($quantityMaps)
            ->flatMap(
                fn (array $quantities): array =>
                    array_keys($quantities)
            )
            ->map(
                fn (mixed $skuId): int => (int) $skuId
            )
            ->unique()
            ->values();

        if ($skuIds->isEmpty()) {
            return [];
        }

        return Sku::query()
            ->whereIn('id', $skuIds)
            ->with([
                'product:id,model_code,name_hu',
                'color:id,code,name_hu',
                'size:id,code,sort_order',
            ])
            ->get()
            ->mapWithKeys(function (Sku $sku): array {
                return [
                    (int) $sku->id => [
                        'sku_id' =>
                            (int) $sku->id,
                        'sku_code' =>
                            (string) $sku->sku_code,

                        'product_id' =>
                            (int) $sku->product_id,
                        'model_code' =>
                            (string) (
                                $sku->product?->model_code ?? ''
                            ),
                        'product_name' =>
                            (string) (
                                $sku->product?->name_hu ?? ''
                            ),

                        'color_id' =>
                            (int) $sku->color_id,
                        'color_code' =>
                            (string) (
                                $sku->color?->code ?? ''
                            ),
                        'color_name' =>
                            (string) (
                                $sku->color?->name_hu ?? ''
                            ),

                        'size_id' =>
                            (int) $sku->size_id,
                        'size_code' =>
                            (string) (
                                $sku->size?->code ?? ''
                            ),
                        'size_sort_order' =>
                            (int) (
                                $sku->size?->sort_order ?? 0
                            ),
                    ],
                ];
            })
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $skuMetadata
     *
     * @return Collection<string, Collection<int, int>>
     */
    protected function groupSkuIdsByProductAndColor(
        array $skuMetadata
    ): Collection {
        return collect($skuMetadata)
            ->groupBy(
                fn (array $metadata): string =>
                    $metadata['product_id']
                    . '|'
                    . $metadata['color_id']
            )
            ->map(
                fn (Collection $items): Collection =>
                    $items
                        ->sortBy([
                            ['size_sort_order', 'asc'],
                            ['size_code', 'asc'],
                        ])
                        ->pluck('sku_id')
                        ->map(
                            fn (mixed $skuId): int =>
                                (int) $skuId
                        )
                        ->values()
            );
    }

    protected function roundToNearestFive(
        float $quantity
    ): int {
        return (int) (
            round(
                $quantity / 5,
                0,
                PHP_ROUND_HALF_UP
            ) * 5
        );
    }
}