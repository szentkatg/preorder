<?php

namespace App\Imports;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceListItem;
use App\Models\Sku;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;

class PartnerOrderMatrixImport implements ToCollection
{
    protected int $createdCount = 0;

    protected int $updatedCount = 0;

    protected int $deletedCount = 0;

    protected int $unchangedCount = 0;

    protected int $invalidCount = 0;

    protected int $mappedCellCount = 0;

    protected int $rowsWithMetaCount = 0;

    protected array $warnings = [];

    public function __construct(
        protected Order $order
    ) {
        $this->order->loadMissing(['priceList']);
    }

    public function collection(Collection $rows): void
    {
        if ($this->isSubmittedOrder($this->order)) {
            $this->warnings[] = [
                'row' => null,
                'column' => null,
                'sku_id' => null,
                'value' => null,
                'message' => 'A rendelés már le van zárva.',
            ];

            return;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $rowIndex => $row) {
                $excelRowNumber = $rowIndex + 1;
                $values = $row->toArray();

                $meta = $this->parseImportMeta($values[0] ?? null, $excelRowNumber);

                if (! $meta) {
                    continue;
                }

                $this->rowsWithMetaCount++;

                foreach ($meta['skus'] as $columnIndex => $skuId) {
                    $columnIndex = (int) $columnIndex;
                    $skuId = (int) $skuId;

                    if ($columnIndex < 1 || $skuId < 1) {
                        $this->invalidCount++;
                        $this->warnings[] = [
                            'row' => $excelRowNumber,
                            'column' => $columnIndex,
                            'sku_id' => $skuId,
                            'value' => null,
                            'message' => 'Hibás import metaadat.',
                        ];

                        continue;
                    }

                    $this->mappedCellCount++;

                    $value = $values[$columnIndex - 1] ?? null;

                    $this->applyQuantityBySkuId($skuId, $value, $excelRowNumber, $columnIndex);
                }
            }
        });
    }

    public function stats(): array
    {
        $changedCount = $this->createdCount + $this->updatedCount + $this->deletedCount;

        return [
            'created' => $this->createdCount,
            'updated' => $this->updatedCount,
            'deleted' => $this->deletedCount,
            'unchanged' => $this->unchangedCount,
            'invalid' => $this->invalidCount,
            'changed' => $changedCount,
            'mapped_cells' => $this->mappedCellCount,
            'rows_with_meta' => $this->rowsWithMetaCount,
            'warnings' => $this->warnings,
        ];
    }

    protected function parseImportMeta(mixed $value, int $excelRowNumber): ?array
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $rawValue = trim((string) $value);

        // Csoport fejléc sorok / normál szöveges sorok átugrása.
        // Ezek nem import hibák.
        if (! str_starts_with($rawValue, '{')) {
            return null;
        }

        $meta = json_decode($rawValue, true);

        if (! is_array($meta)) {
            $this->invalidCount++;
            $this->warnings[] = [
                'row' => $excelRowNumber,
                'column' => 1,
                'sku_id' => null,
                'value' => $rawValue,
                'message' => 'Érvénytelen import JSON.',
            ];

            return null;
        }

        // Ha később kerül type mező a JSON-be, csak az item sorokat dolgozzuk fel.
        if (isset($meta['type']) && $meta['type'] !== 'item') {
            return null;
        }

        if (($meta['version'] ?? null) !== 1) {
            $this->invalidCount++;
            $this->warnings[] = [
                'row' => $excelRowNumber,
                'column' => 1,
                'sku_id' => null,
                'value' => $rawValue,
                'message' => 'Nem támogatott import formátum verzió.',
            ];

            return null;
        }

        if (! isset($meta['skus']) || ! is_array($meta['skus'])) {
            return null;
        }

        if ($meta['skus'] === []) {
            return null;
        }

        return $meta;
    }

    protected function applyQuantityBySkuId(int $skuId, mixed $value, int $excelRowNumber, int $columnIndex): void
    {
        if ($this->isEmptyImportValue($value)) {
            return;
        }

        $quantity = $this->parseQuantity($value);

        if ($quantity === null) {
            $this->invalidCount++;
            $this->warnings[] = [
                'row' => $excelRowNumber,
                'column' => $columnIndex,
                'sku_id' => $skuId,
                'value' => $value,
                'message' => 'Érvénytelen mennyiség.',
            ];

            return;
        }

        if ($quantity <= 0) {
            $deleted = OrderItem::query()
                ->where('order_id', $this->order->id)
                ->where('sku_id', $skuId)
                ->delete();

            if ($deleted > 0) {
                $this->deletedCount += $deleted;
            } else {
                $this->unchangedCount++;
            }

            return;
        }

        $sku = Sku::query()
            ->with('assortmentComponents')
            ->find($skuId);

        if (! $sku) {
            $this->invalidCount++;
            $this->warnings[] = [
                'row' => $excelRowNumber,
                'column' => $columnIndex,
                'sku_id' => $skuId,
                'value' => $value,
                'message' => 'Nem található SKU.',
            ];

            return;
        }

        $unitPrice = $this->getProductPrice(
            (int) $sku->product_id,
            $this->order->price_list_id,
            $this->order->season_id
        );

        $assortmentContent = (int) $sku->assortmentComponents->sum('quantity');

        $lineTotal = $assortmentContent > 0
            ? $quantity * $assortmentContent * $unitPrice
            : $quantity * $unitPrice;

        $existing = OrderItem::query()
            ->where('order_id', $this->order->id)
            ->where('sku_id', $sku->id)
            ->first();

        if ($existing && (int) $existing->quantity === $quantity) {
            $existing->update([
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]);

            $this->unchangedCount++;

            return;
        }

        OrderItem::query()->updateOrCreate(
            [
                'order_id' => $this->order->id,
                'sku_id' => $sku->id,
            ],
            [
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]
        );

        if ($existing) {
            $this->updatedCount++;
        } else {
            $this->createdCount++;
        }
    }

    protected function parseQuantity(mixed $value): ?int
    {
        if ($this->isEmptyImportValue($value) || $value === '-') {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            $value = str_replace(' ', '', $value);
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    protected function isEmptyImportValue(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    protected function getProductPrice(
        int $productId,
        ?int $priceListId,
        ?int $seasonId
    ): float
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

    protected function isSubmittedOrder(Order $order): bool
    {
        return method_exists($order, 'isSubmitted') && $order->isSubmitted();
    }
}