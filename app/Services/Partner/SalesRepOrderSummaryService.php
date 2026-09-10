<?php

namespace App\Services\Partner;

use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesRepOrderSummaryService
{
    public function build(Collection $orders): Collection
    {
        $buildStartedAt = microtime(true);

        if ($orders->isEmpty()) {
            return collect();
        }

        /*
         * A rendelésekhez szükséges alapadatokat egyszerű PHP-tömbben
         * készítjük elő, hogy a rendelési tételek feldolgozásakor
         * ne kelljen újra és újra Eloquent kapcsolatokat elérni.
         */
        $orderContextById = [];

        foreach ($orders as $order) {
            $orderId = (int) $order->id;

            $orderContextById[$orderId] = [
                'season_id' => (int) $order->season_id,
                'price_list_id' => (int) $order->price_list_id,
                'retail_price_list_id' => $order->priceList?->retail_price_list_id
                    ? (int) $order->priceList->retail_price_list_id
                    : null,
            ];
        }

        $orderIds = array_keys($orderContextById);

        /*
         * Csak a ténylegesen szükséges mezőket kérjük le.
         *
         * Nem készül:
         * - OrderItem Eloquent modell,
         * - Sku Eloquent modell,
         * - assortmentComponents kollekció.
         */
        $startedAt = microtime(true);

        $orderItemRows = DB::table('order_items')
            ->join('skus', 'skus.id', '=', 'order_items.sku_id')
            ->whereIn('order_items.order_id', $orderIds)
            ->get([
                'order_items.order_id',
                'order_items.sku_id',
                'order_items.quantity',
                'skus.product_id',
            ]);

        Log::info('SummaryService: order item rows', [
            'row_count' => $orderItemRows->count(),
            'duration_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
        ]);

        /*
         * Az érintett SKU-kat egyszer gyűjtjük ki.
         */
        $skuIds = $orderItemRows
            ->pluck('sku_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        /*
         * Az assortment SKU-k teljes tartalmát adatbázisban összegezzük.
         *
         * Az eredmény például:
         *
         * [
         *     123 => 12,
         *     456 => 8,
         * ]
         *
         * ahol a kulcs az assortment_sku_id, az érték pedig
         * a komponensek összes mennyisége.
         */
        $startedAt = microtime(true);

        $assortmentMultiplierBySkuId = [];

        if ($skuIds !== []) {
            $assortmentRows = DB::table('item_assortments')
                ->whereIn('assortment_sku_id', $skuIds)
                ->groupBy('assortment_sku_id')
                ->get([
                    'assortment_sku_id',
                    DB::raw('SUM(quantity) AS total_quantity'),
                ]);

            foreach ($assortmentRows as $row) {
                $assortmentMultiplierBySkuId[
                    (int) $row->assortment_sku_id
                ] = (int) $row->total_quantity;
            }
        }

        Log::info('SummaryService: assortment multipliers', [
            'sku_count' => count($assortmentMultiplierBySkuId),
            'duration_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
        ]);

        $productIds = $orderItemRows
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $seasonIds = $orders
            ->pluck('season_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $priceListIds = [];

        foreach ($orders as $order) {
            if ($order->price_list_id) {
                $priceListIds[] = (int) $order->price_list_id;
            }

            if ($order->priceList?->retail_price_list_id) {
                $priceListIds[] =
                    (int) $order->priceList->retail_price_list_id;
            }
        }

        $priceListIds = array_values(array_unique($priceListIds));

        /*
         * Az árakat egyszerű PHP-tömbbe töltjük.
         *
         * Így a fő ciklusban nincs Collection::get(),
         * Eloquent modell vagy property-feloldás.
         */
        $startedAt = microtime(true);

        $priceByKey = [];

        if (
            $priceListIds !== []
            && $seasonIds !== []
            && $productIds !== []
        ) {
            $priceRows = DB::table('price_list_items')
                ->whereIn('price_list_id', $priceListIds)
                ->whereIn('season_id', $seasonIds)
                ->whereIn('product_id', $productIds)
                ->get([
                    'price_list_id',
                    'season_id',
                    'product_id',
                    'net_price',
                ]);

            foreach ($priceRows as $row) {
                $priceByKey[$this->priceKey(
                    (int) $row->price_list_id,
                    (int) $row->season_id,
                    (int) $row->product_id,
                )] = (float) $row->net_price;
            }
        }

        Log::info('SummaryService: prices', [
            'price_count' => count($priceByKey),
            'duration_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
        ]);

        $currencyIds = $orders
            ->pluck('priceList.currency_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        /*
         * Árfolyamok egyszerű PHP-tömbben.
         */
        $startedAt = microtime(true);

        $exchangeRateByKey = [];

        if ($seasonIds !== [] && $currencyIds !== []) {
            $exchangeRateRows = DB::table('exchange_rates')
                ->whereIn('season_id', $seasonIds)
                ->whereIn('currency_id', $currencyIds)
                ->where('active', true)
                ->get([
                    'season_id',
                    'currency_id',
                    'rate_to_huf',
                ]);

            foreach ($exchangeRateRows as $row) {
                $exchangeRateByKey[$this->exchangeRateKey(
                    (int) $row->season_id,
                    (int) $row->currency_id,
                )] = (float) $row->rate_to_huf;
            }
        }

        Log::info('SummaryService: exchange rates', [
            'rate_count' => count($exchangeRateByKey),
            'duration_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
        ]);

        /*
         * Rendelésenkénti számszerű összesítések előkészítése.
         */
        $totalsByOrderId = [];

        foreach ($orderIds as $orderId) {
            $totalsByOrderId[$orderId] = [
                'quantity' => 0,
                'wholesale_value' => 0.0,
                'retail_value' => 0.0,
            ];
        }

        /*
         * Egyetlen lineáris ciklus az összes rendelési tételen.
         *
         * Nincs rendelésenként külön Collection,
         * nincs SKU-kapcsolat,
         * nincs assortment kollekció,
         * nincs ismételt sum().
         */
        $startedAt = microtime(true);

        foreach ($orderItemRows as $row) {
            $orderId = (int) $row->order_id;
            $skuId = (int) $row->sku_id;
            $productId = (int) $row->product_id;
            $quantity = (int) $row->quantity;

            $orderContext = $orderContextById[$orderId] ?? null;

            if (! $orderContext) {
                continue;
            }

            $assortmentContent =
                $assortmentMultiplierBySkuId[$skuId] ?? 0;

            $effectiveQuantity = $quantity
                * ($assortmentContent > 0 ? $assortmentContent : 1);

            $wholesalePrice = $priceByKey[$this->priceKey(
                $orderContext['price_list_id'],
                $orderContext['season_id'],
                $productId,
            )] ?? 0.0;

            $retailPrice = 0.0;

            if ($orderContext['retail_price_list_id']) {
                $retailPrice = $priceByKey[$this->priceKey(
                    $orderContext['retail_price_list_id'],
                    $orderContext['season_id'],
                    $productId,
                )] ?? 0.0;
            }

            $totalsByOrderId[$orderId]['quantity'] +=
                $effectiveQuantity;

            $totalsByOrderId[$orderId]['wholesale_value'] +=
                $effectiveQuantity * $wholesalePrice;

            $totalsByOrderId[$orderId]['retail_value'] +=
                $effectiveQuantity * $retailPrice;
        }

        Log::info('SummaryService: numeric aggregation', [
            'duration_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
        ]);

        /*
         * A végső megjelenítési tömb összeállítása.
         *
         * Itt már rendelésenként csak egyetlen egyszer olvassuk ki
         * a kapcsolódó partner-, cím-, szezon- és pénznemadatokat.
         */
        $startedAt = microtime(true);

        $summaries = $orders
            ->map(function (Order $order) use (
                $totalsByOrderId,
                $exchangeRateByKey
            ): array {
                $orderId = (int) $order->id;

                $totals = $totalsByOrderId[$orderId] ?? [
                    'quantity' => 0,
                    'wholesale_value' => 0.0,
                    'retail_value' => 0.0,
                ];

                $currencyId = $order->priceList?->currency_id;

                $rateToHuf = 1.0;

                if ($currencyId) {
                    $rateToHuf = $exchangeRateByKey[
                        $this->exchangeRateKey(
                            (int) $order->season_id,
                            (int) $currencyId,
                        )
                    ] ?? 1.0;
                }

                $wholesaleValue =
                    (float) $totals['wholesale_value'];

                $retailValue =
                    (float) $totals['retail_value'];

                return [
                    'order_id' => $orderId,
                    'season_id' => (int) $order->season_id,
                    'brand_id' => (int) $order->brand_id,
                    'order_sheet_type_id' => (int) $order->order_sheet_type_id,

                    'partner_code' => $order->partner?->erp_partner_code ?? '',

                    'partner_name' => $order->partner?->name ?? '',

                    'address_name' => $order->partnerAddress?->name
                        ?? $order->partnerAddress?->addrid
                        ?? '',

                    'address' => $this->formatAddress(
                        $order->partnerAddress
                    ),

                    'season' => $order->season?->name ?? '',
                    'brand' => $order->brand?->name ?? '',

                    'type' => $order->orderSheetType?->translate('name'),

                    'reference_number' => $order->reference_number ?? '',

                    'order_type' => $order->orderType?->translate('name'),

                    'status' => $order->status,

                    'status_label' => match ($order->status) {
                        'submitted' => __('partner.status_submitted'),

                        'draft' => __('partner.status_draft'),

                        default => __('partner.status_in_progress'),
                    },

                    'status_icon' => match ($order->status) {
                        'submitted' => '✅',
                        'draft' => '📝',
                        default => '⏳',
                    },

                    'currency' => $order->priceList?->currency?->symbol
                        ?? $order->priceList?->currency?->code
                        ?? '',

                    'quantity' => (int) $totals['quantity'],

                    'wholesale_value' => $wholesaleValue,

                    'retail_value' => $retailValue,

                    'wholesale_value_huf' => $wholesaleValue * $rateToHuf,

                    'retail_value_huf' => $retailValue * $rateToHuf,

                    'rate_to_huf' => $rateToHuf,
                ];
            })
            ->values();

        Log::info('SummaryService: result mapping', [
            'duration_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
        ]);

        Log::info('SummaryService: TOTAL', [
            'duration_ms' => round(
                (microtime(true) - $buildStartedAt) * 1000,
                2
            ),
        ]);

        return $summaries;
    }

    protected function priceKey(
        int $priceListId,
        int $seasonId,
        int $productId,
    ): string {
        return $priceListId
            .':'
            .$seasonId
            .':'
            .$productId;
    }

    protected function exchangeRateKey(
        int $seasonId,
        int $currencyId,
    ): string {
        return $seasonId.':'.$currencyId;
    }

    protected function formatAddress($address): string
    {
        if (! $address) {
            return '';
        }

        return trim(
            collect([
                $address->country_code ?? null,

                trim(
                    collect([
                        $address->zip
                            ?? $address->postal_code
                            ?? null,

                        $address->city ?? null,
                    ])
                        ->filter()
                        ->implode(' ')
                ),

                $address->street
                    ?? $address->address
                    ?? null,
            ])
                ->filter()
                ->implode(' - ')
        );
    }
}
