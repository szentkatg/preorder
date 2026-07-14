<?php

namespace App\Services\Imports;

use App\Models\Color;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductPurchasePrice;
use App\Models\Supplier;

class ProductPurchasePriceImporter
{
    public function __construct(
        protected SpreadsheetHelper $spreadsheetHelper,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     skipped: int
     * }
     */
    public function import(array $rows): array
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        $products = Product::query()
            ->whereNotNull('model_code')
            ->get()
            ->keyBy(
                fn (Product $product): string =>
                    $this->normalizeCode($product->model_code)
            );

        $suppliers = Supplier::query()
            ->whereNotNull('erp_partner_code')
            ->get()
            ->keyBy(
                fn (Supplier $supplier): string =>
                    $this->normalizeCode($supplier->erp_partner_code)
            );

        $currencies = Currency::query()
            ->whereNotNull('code')
            ->get()
            ->keyBy(
                fn (Currency $currency): string =>
                    $this->normalizeCode($currency->code)
            );

        $colors = Color::query()
            ->with('product:id,model_code')
            ->whereNotNull('code')
            ->get()
            ->keyBy(function (Color $color): string {
                return $this->normalizeCode(
                    ($color->product?->model_code ?? '')
                    . '|'
                    . $color->code
                );
            });

        foreach ($rows as $row) {
            $modelCode = $this->spreadsheetHelper->nullIfEmpty(
                $row['model_code'] ?? null
            );

            $colorCode = $this->spreadsheetHelper->nullIfEmpty(
                $row['color_code'] ?? null
            );

            $supplierCode = $this->spreadsheetHelper->nullIfEmpty(
                $row['supplier_code'] ?? null
            );

            $currencyCode = $this->spreadsheetHelper->nullIfEmpty(
                $row['currency_code'] ?? null
            );

            $purchasePrice = $this->spreadsheetHelper->decimalValue(
                $row['purchase_price'] ?? null
            );

            if (
                ! $modelCode
                || ! $supplierCode
                || ! $currencyCode
                || $purchasePrice === null
                || $purchasePrice < 0
            ) {
                $skipped++;

                continue;
            }

            $product = $products->get(
                $this->normalizeCode($modelCode)
            );

            $supplier = $suppliers->get(
                $this->normalizeCode($supplierCode)
            );

            $currency = $currencies->get(
                $this->normalizeCode($currencyCode)
            );

            if (! $product || ! $supplier || ! $currency) {
                $skipped++;

                continue;
            }

            $color = null;

            if ($colorCode !== null) {
                $color = $colors->get(
                    $this->normalizeCode(
                        $modelCode . '|' . $colorCode
                    )
                );

                if (! $color) {
                    $skipped++;

                    continue;
                }
            }

            $colorKey = $color?->id ?? 0;

            $purchasePriceRecord = ProductPurchasePrice::query()
                ->firstOrNew([
                    'product_id' => $product->id,
                    'supplier_id' => $supplier->getKey(),
                    'color_key' => $colorKey,
                ]);

            $isNew = ! $purchasePriceRecord->exists;

            $purchasePriceRecord->color_id = $color?->id;
            $purchasePriceRecord->currency_id = $currency->id;
            $purchasePriceRecord->purchase_price = $purchasePrice;

            $activeRaw = $row['active'] ?? null;

            $activeIsEmpty =
                $activeRaw === null
                || trim((string) $activeRaw) === '';

            if ($isNew) {
                $purchasePriceRecord->active = $activeIsEmpty
                    ? true
                    : $this->spreadsheetHelper->boolValue(
                        $activeRaw,
                        true
                    );
            } elseif (! $activeIsEmpty) {
                $purchasePriceRecord->active =
                    $this->spreadsheetHelper->boolValue(
                        $activeRaw,
                        (bool) $purchasePriceRecord->active
                    );
            }

            if ($isNew) {
                $purchasePriceRecord->save();
                $created++;

                continue;
            }

            if (! $purchasePriceRecord->isDirty()) {
                $unchanged++;

                continue;
            }

            $purchasePriceRecord->save();
            $updated++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
        ];
    }

    protected function normalizeCode(mixed $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }
}