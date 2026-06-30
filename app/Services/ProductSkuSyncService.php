<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sku;
use Illuminate\Support\Facades\DB;

class ProductSkuSyncService
{
    public function sync(Product $product): array
    {
        $created = 0;
        $updated = 0;
        $deactivated = 0;

        $product->load([
            'sizeRange.items.size',
            'colors',
        ]);

        if (! $product->sizeRange) {
            return [
                'created' => 0,
                'updated' => 0,
                'deactivated' => 0,
                'skipped' => 1,
            ];
        }

        DB::transaction(function () use ($product, &$created, &$updated, &$deactivated) {
            $expectedSkuCodes = [];

            foreach ($product->colors as $color) {
                foreach ($product->sizeRange->items as $item) {
                    $size = $item->size;

                    if (! $size) {
                        continue;
                    }

                    $skuCode = $product->model_code . $color->code . '.' . $size->code;

                    $expectedSkuCodes[] = $skuCode;

                    $sku = Sku::firstOrNew([
                        'sku_code' => $skuCode,
                    ]);

                    $exists = $sku->exists;

                    $sku->forceFill([
                        'product_id' => $product->id,
                        'color_id' => $color->id,
                        'size_id' => $size->id,
                        'sku_name' => mb_strtoupper($product->name_hu . ' ' . $color->name_hu . ' ' . $size->code),
                        'type' => 'normal',
                        'active' => true,
                    ]);

                    $sku->save();

                    $exists ? $updated++ : $created++;
                }

                $assortmentSkuCode = $product->model_code . $color->code . '.GY';

                $expectedSkuCodes[] = $assortmentSkuCode;

                $sku = Sku::firstOrNew([
                    'sku_code' => $assortmentSkuCode,
                ]);

                $exists = $sku->exists;

                $sku->forceFill([
                    'product_id' => $product->id,
                    'color_id' => $color->id,
                    'size_id' => null,
                    'sku_code' => $assortmentSkuCode,
                    'sku_name' => mb_strtoupper($product->name_hu . ' ' . $color->name_hu . ' GY'),
                    'type' => 'assortment',
                    'active' => true,
                ]);

                $sku->save();

                $exists ? $updated++ : $created++;
            }

            $deactivated = Sku::query()
                ->where('product_id', $product->id)
                ->whereNotIn('sku_code', $expectedSkuCodes)
                ->where('active', true)
                ->update([
                    'active' => false,
                ]);
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'deactivated' => $deactivated,
            'skipped' => 0,
        ];
    }
}