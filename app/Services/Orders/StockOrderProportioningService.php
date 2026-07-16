<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockOrderProportioningService
{
    /**
     * Előnézet készítése adatbázis-módosítás nélkül.
     *
     * @return array{
     *     scope: array<string, int>,
     *     summary: array<string, int>,
     *     groups: array<int, array<string, mixed>>,
     *     items: array<int, array<string, mixed>>
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

        /*
         * A normál partneri rendelések összesítése.
         *
         * Az arány- és készletrendelést kizárjuk, de a státusz alapján
         * nem szűrünk: minden, azonos szezonhoz, márkához és
         * rendelőlap-típushoz tartozó rendelés bekerül.
         */
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
            ->map(fn (mixed $id): int => (int) $id)
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

        $resultItems = [];
        $resultGroups = [];

        foreach ($groupedSkuIds as $groupKey => $skuIds) {
            $firstSkuId = $skuIds->first();
            $firstMetadata = $skuMetadata[$firstSkuId];

            $plannedStockTotal = $skuIds->sum(
                fn (int $skuId): int =>
                    (int) ($stockPlanQuantities[$skuId] ?? 0)
            );

            $ratioSum = $skuIds->sum(
                fn (int $skuId): int =>
                    max(0, (int) ($ratioQuantities[$skuId] ?? 0))
            );

            $partnerGroupTotal = 0;
            $newStockGroupTotal = 0;
            $finalGroupTotal = 0;
            $negativeCorrectionCount = 0;
            $groupItems = [];

            foreach ($skuIds as $skuId) {
                $metadata = $skuMetadata[$skuId];

                $partnerQuantity = (int) (
                    $partnerQuantities[$skuId] ?? 0
                );

                $ratio = max(
                    0,
                    (int) ($ratioQuantities[$skuId] ?? 0)
                );

                /*
                 * Ha az adott termék-szín arányösszege nulla,
                 * akkor a végső célmennyiség nulla.
                 *
                 * Ez teszi lehetővé egy nem gyártandó modell vagy szín
                 * teljes partneri mennyiségének kiütését.
                 */
                if ($ratioSum === 0) {
                    $rawAllocatedStock = 0.0;
                    $roundedFinalQuantity = 0;
                } else {
                    $rawAllocatedStock =
                        $plannedStockTotal * $ratio / $ratioSum;

                    $rawCombinedQuantity =
                        $partnerQuantity + $rawAllocatedStock;

                    $roundedFinalQuantity = $this->roundToNearestFive(
                        $rawCombinedQuantity
                    );
                }

                $newStockQuantity =
                    $roundedFinalQuantity - $partnerQuantity;

                if ($newStockQuantity < 0) {
                    $negativeCorrectionCount++;
                }

                $item = [
                    'group_key' => $groupKey,
                    'sku_id' => $skuId,
                    'sku_code' => $metadata['sku_code'],
                    'product_id' => $metadata['product_id'],
                    'model_code' => $metadata['model_code'],
                    'product_name' => $metadata['product_name'],
                    'color_id' => $metadata['color_id'],
                    'color_code' => $metadata['color_code'],
                    'color_name' => $metadata['color_name'],
                    'size_id' => $metadata['size_id'],
                    'size_code' => $metadata['size_code'],
                    'size_sort_order' =>
                        $metadata['size_sort_order'],

                    'partner_quantity' => $partnerQuantity,
                    'planned_stock_total' => $plannedStockTotal,
                    'ratio' => $ratio,
                    'ratio_sum' => $ratioSum,
                    'raw_allocated_stock' =>
                        round($rawAllocatedStock, 4),
                    'new_stock_quantity' => $newStockQuantity,
                    'final_quantity' => $roundedFinalQuantity,
                ];

                $groupItems[] = $item;
                $resultItems[] = $item;

                $partnerGroupTotal += $partnerQuantity;
                $newStockGroupTotal += $newStockQuantity;
                $finalGroupTotal += $roundedFinalQuantity;
            }

            $resultGroups[] = [
                'group_key' => $groupKey,
                'product_id' => $firstMetadata['product_id'],
                'model_code' => $firstMetadata['model_code'],
                'product_name' => $firstMetadata['product_name'],
                'color_id' => $firstMetadata['color_id'],
                'color_code' => $firstMetadata['color_code'],
                'color_name' => $firstMetadata['color_name'],

                'planned_stock_total' => $plannedStockTotal,
                'ratio_sum' => $ratioSum,
                'partner_quantity' => $partnerGroupTotal,
                'new_stock_quantity' => $newStockGroupTotal,
                'final_quantity' => $finalGroupTotal,
                'negative_correction_count' =>
                    $negativeCorrectionCount,
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

        usort(
            $resultItems,
            fn (array $left, array $right): int =>
                [
                    $left['model_code'],
                    $left['color_code'],
                    $left['size_sort_order'],
                    $left['size_code'],
                ] <=> [
                    $right['model_code'],
                    $right['color_code'],
                    $right['size_sort_order'],
                    $right['size_code'],
                ]
        );

        return [
            'scope' => [
                'season_id' => (int) $stockOrder->season_id,
                'brand_id' => (int) $stockOrder->brand_id,
                'order_sheet_type_id' =>
                    (int) $stockOrder->order_sheet_type_id,
                'ratio_order_id' => $ratioOrder->id,
                'stock_order_id' => $stockOrder->id,
                'partner_order_count' => count($partnerOrderIds),
            ],
            'summary' => [
                'group_count' => count($resultGroups),
                'size_count' => count($resultItems),
                'partner_quantity' => array_sum(
                    array_column(
                        $resultItems,
                        'partner_quantity'
                    )
                ),
                'original_stock_quantity' => array_sum(
                    $stockPlanQuantities
                ),
                'new_stock_quantity' => array_sum(
                    array_column(
                        $resultItems,
                        'new_stock_quantity'
                    )
                ),
                'final_quantity' => array_sum(
                    array_column(
                        $resultItems,
                        'final_quantity'
                    )
                ),
                'negative_correction_count' => count(
                    array_filter(
                        $resultItems,
                        fn (array $item): bool =>
                            $item['new_stock_quantity'] < 0
                    )
                ),
            ],
            'groups' => $resultGroups,
        ];
    }

    /**
     * A cache-ben tárolt, korábban kiszámított előnézet alapján
     * felülírja a készletrendelés tételeit.
     *
     * Nem végez újraszámítást, ezért pontosan azok a mennyiségek
     * kerülnek mentésre, amelyeket a felhasználó az előnézetben látott.
     *
     * @param array<string, mixed> $preview
     *
     * @return array<string, mixed>
     */
    public function apply(
        array $preview,
        int $ratioOrderId,
        int $stockOrderId
    ): array {
        $scope = $preview['scope'] ?? [];
        $groups = $preview['groups'] ?? [];

        if ($groups === []) {
            throw new InvalidArgumentException(
                'Az előnézet nem tartalmaz menthető tételeket. '
                . 'Készíts új számítást.'
            );
        }

        if (
            (int) ($scope['ratio_order_id'] ?? 0) !== $ratioOrderId
            || (int) ($scope['stock_order_id'] ?? 0) !== $stockOrderId
        ) {
            throw new InvalidArgumentException(
                'Az előnézet nem a jelenleg kiválasztott rendelésekhez '
                . 'tartozik. Készíts új számítást.'
            );
        }

        /*
        * Ellenőrizzük, hogy a két rendelés továbbra is létezik,
        * és azonos szezonhoz, márkához és rendelőlap-típushoz tartozik.
        */
        [, $stockOrder] = $this->loadAndValidateOrders(
            $ratioOrderId,
            $stockOrderId
        );

        if (
            (int) ($scope['season_id'] ?? 0)
                !== (int) $stockOrder->season_id
            || (int) ($scope['brand_id'] ?? 0)
                !== (int) $stockOrder->brand_id
            || (int) ($scope['order_sheet_type_id'] ?? 0)
                !== (int) $stockOrder->order_sheet_type_id
        ) {
            throw new InvalidArgumentException(
                'A rendelés adatai megváltoztak az előnézet elkészítése óta. '
                . 'Készíts új számítást.'
            );
        }

        DB::transaction(function () use (
            $stockOrderId,
            $groups
        ): void {
            /*
            * A készletrendelést zároljuk a tranzakció végéig.
            * Így ugyanarra a rendelésre nem futhat egyszerre két felülírás.
            */
            $lockedStockOrder = Order::query()
                ->whereKey($stockOrderId)
                ->lockForUpdate()
                ->firstOrFail();

            /*
            * A meglévő egységárakat még a törlés előtt eltesszük.
            */
            $existingPrices = OrderItem::query()
                ->where('order_id', $lockedStockOrder->id)
                ->pluck('unit_price', 'sku_id');

            /*
            * SKU-nként pontosan egy mentendő sort építünk.
            */
            $quantitiesBySku = [];

            foreach ($groups as $group) {
                foreach ($group['items'] ?? [] as $item) {
                    $skuId = (int) ($item['sku_id'] ?? 0);

                    if ($skuId <= 0) {
                        continue;
                    }

                    $quantity = (int) (
                        $item['new_stock_quantity'] ?? 0
                    );

                    /*
                    * Ugyanaz a SKU normál esetben csak egyszer szerepelhet.
                    * A hozzárendeléssel azt is biztosítjuk, hogy véletlen
                    * duplikáció esetén se szúrjuk be többször.
                    */
                    $quantitiesBySku[$skuId] = $quantity;
                }
            }

            $lockedStockOrder->items()->delete();

            $now = now();
            $rows = [];

            foreach ($quantitiesBySku as $skuId => $quantity) {
                /*
                * Nulla mennyiséghez nem tartunk fenn order_items rekordot.
                * Negatív érték viszont szabályosan mentésre kerül.
                */
                if ($quantity === 0) {
                    continue;
                }

                $unitPrice = (float) (
                    $existingPrices[$skuId] ?? 0
                );

                $rows[] = [
                    'order_id' => $lockedStockOrder->id,
                    'sku_id' => $skuId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $quantity * $unitPrice,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                OrderItem::query()->insert($chunk);
            }
        });

        return [
            'scope' => $preview['scope'] ?? [],
            'summary' => $preview['summary'] ?? [],
            'groups' => [],
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
        if ($ratioOrderId === $stockOrderId) {
            throw new InvalidArgumentException(
                'Az arányrendelés és a készletrendelés nem lehet ugyanaz.'
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
                'order_sheet_type_id' => 'rendelőlap-típus',
            ] as $field => $label
        ) {
            if ($ratioOrder->{$field} !== $stockOrder->{$field}) {
                throw new InvalidArgumentException(
                    "A két rendelés {$label} értéke nem azonos."
                );
            }
        }

        return [$ratioOrder, $stockOrder];
    }

    /**
     * A rendeléstételeket normál méret-SKU mennyiségekre bontja.
     *
     * A normál SKU közvetlenül bekerül.
     * A gyűjtő SKU a hozzá tartozó komponensekre bomlik.
     *
     * @param Collection<int, Order> $orders
     *
     * @return array<int, int> [sku_id => quantity]
     */
/**
 * A megadott rendelések tételeit méret-SKU szintre összesíti.
 *
 * A normál SKU-k közvetlenül kerülnek az eredménybe.
 * A gyűjtő SKU-kat az item_assortments összetétele bontja fel.
 *
 * @param array<int, int> $orderIds
 *
 * @return array<int, int> [sku_id => quantity]
 */
    protected function aggregateExpandedQuantitiesForOrderIds(
        array $orderIds
    ): array {
        if ($orderIds === []) {
            return [];
        }

        /*
        * Normál SKU-k: csak azok, amelyekhez nincs gyűjtőösszetétel.
        */
        $directQuantities = DB::table('order_items')
            ->whereIn('order_items.order_id', $orderIds)
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
                'order_items.sku_id, SUM(order_items.quantity) AS quantity'
            )
            ->pluck('quantity', 'sku_id');

        /*
        * Gyűjtő SKU-k: a rendelt gyűjtőmennyiség szorozva az egyes
        * komponensek gyűjtőn belüli mennyiségével.
        */
        $assortmentQuantities = DB::table('order_items')
            ->join(
                'item_assortments',
                'item_assortments.assortment_sku_id',
                '=',
                'order_items.sku_id'
            )
            ->whereIn('order_items.order_id', $orderIds)
            ->groupBy('item_assortments.component_sku_id')
            ->selectRaw(
                'item_assortments.component_sku_id AS sku_id, '
                . 'SUM(order_items.quantity * item_assortments.quantity) '
                . 'AS quantity'
            )
            ->pluck('quantity', 'sku_id');

        $result = [];

        foreach ($directQuantities as $skuId => $quantity) {
            $result[(int) $skuId] =
                ($result[(int) $skuId] ?? 0) + (int) $quantity;
        }

        foreach ($assortmentQuantities as $skuId => $quantity) {
            $result[(int) $skuId] =
                ($result[(int) $skuId] ?? 0) + (int) $quantity;
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
            ->map(fn (mixed $skuId): int => (int) $skuId)
            ->unique()
            ->values();

        if ($skuIds->isEmpty()) {
            return [];
        }

        return \App\Models\Sku::query()
            ->whereIn('id', $skuIds)
            ->with([
                'product:id,model_code,name_hu',
                'color:id,code,name_hu',
                'size:id,code,sort_order',
            ])
            ->get()
            ->mapWithKeys(function ($sku): array {
                return [
                    $sku->id => [
                        'sku_id' => (int) $sku->id,
                        'sku_code' => (string) $sku->sku_code,
                        'product_id' => (int) $sku->product_id,
                        'model_code' =>
                            (string) ($sku->product?->model_code ?? ''),
                        'product_name' =>
                            (string) ($sku->product?->name_hu ?? ''),
                        'color_id' => (int) $sku->color_id,
                        'color_code' =>
                            (string) ($sku->color?->code ?? ''),
                        'color_name' =>
                            (string) ($sku->color?->name_hu ?? ''),
                        'size_id' => (int) $sku->size_id,
                        'size_code' =>
                            (string) ($sku->size?->code ?? ''),
                        'size_sort_order' =>
                            (int) ($sku->size?->sort_order ?? 0),
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
                        ->map(fn (mixed $skuId): int => (int) $skuId)
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

    /**
     * @return array<int, string>
     */
}