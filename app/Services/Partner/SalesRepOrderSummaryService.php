<?php

namespace App\Services\Partner;

use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceListItem;
use Illuminate\Support\Collection;

class SalesRepOrderSummaryService
{
    public function build(Collection $orders): Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }

        $orderIds = $orders
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        /*
         * Az összes rendelési tételt egyszerre töltjük be.
         *
         * Ez váltja ki a korábbi rendelésenkénti
         * OrderItem::where('order_id', ...)->get() lekérdezéseket.
         */
        $itemsByOrderId = OrderItem::query()
            ->with([
                'sku.assortmentComponents',
            ])
            ->whereIn('order_id', $orderIds)
            ->get()
            ->groupBy(fn (OrderItem $item): int => (int) $item->order_id);

        $productIds = $itemsByOrderId
            ->flatten(1)
            ->pluck('sku.product_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $seasonIds = $orders
            ->pluck('season_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $priceListIds = $orders
            ->flatMap(function (Order $order): array {
                return [
                    $order->price_list_id,
                    $order->priceList?->retail_price_list_id,
                ];
            })
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        /*
         * Minden szükséges nagykereskedelmi és kiskereskedelmi árat
         * egyetlen lekérdezéssel töltünk be.
         */
        $prices = collect();

        if (
            $priceListIds->isNotEmpty()
            && $seasonIds->isNotEmpty()
            && $productIds->isNotEmpty()
        ) {
            $prices = PriceListItem::query()
                ->whereIn('price_list_id', $priceListIds)
                ->whereIn('season_id', $seasonIds)
                ->whereIn('product_id', $productIds)
                ->get([
                    'price_list_id',
                    'season_id',
                    'product_id',
                    'net_price',
                ])
                ->keyBy(fn (PriceListItem $item): string => $this->priceKey(
                    (int) $item->price_list_id,
                    (int) $item->season_id,
                    (int) $item->product_id,
                ));
        }

        $currencyIds = $orders
            ->pluck('priceList.currency_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        /*
         * Az összes szükséges árfolyamot is egyszerre kérjük le.
         */
        $exchangeRates = collect();

        if ($seasonIds->isNotEmpty() && $currencyIds->isNotEmpty()) {
            $exchangeRates = ExchangeRate::query()
                ->whereIn('season_id', $seasonIds)
                ->whereIn('currency_id', $currencyIds)
                ->where('active', true)
                ->get([
                    'season_id',
                    'currency_id',
                    'rate_to_huf',
                ])
                ->keyBy(fn (ExchangeRate $rate): string => $this->exchangeRateKey(
                    (int) $rate->season_id,
                    (int) $rate->currency_id,
                ));
        }

        return $orders
            ->map(function (Order $order) use (
                $itemsByOrderId,
                $prices,
                $exchangeRates
            ): array {
                $quantity = 0;
                $wholesaleValue = 0.0;
                $retailValue = 0.0;

                $orderItems = $itemsByOrderId->get(
                    (int) $order->id,
                    collect()
                );

                foreach ($orderItems as $item) {
                    if (! $item->sku) {
                        continue;
                    }

                    $assortmentContent = (int) $item
                        ->sku
                        ->assortmentComponents
                        ->sum('quantity');

                    $effectiveQuantity =
                        (int) $item->quantity
                        * ($assortmentContent > 0 ? $assortmentContent : 1);

                    $productId = (int) $item->sku->product_id;

                    $wholesalePrice = $this->findPrice(
                        $prices,
                        (int) $order->price_list_id,
                        (int) $order->season_id,
                        $productId,
                    );

                    $retailPriceListId =
                        $order->priceList?->retail_price_list_id;

                    $retailPrice = $retailPriceListId
                        ? $this->findPrice(
                            $prices,
                            (int) $retailPriceListId,
                            (int) $order->season_id,
                            $productId,
                        )
                        : 0.0;

                    $quantity += $effectiveQuantity;
                    $wholesaleValue += $effectiveQuantity * $wholesalePrice;
                    $retailValue += $effectiveQuantity * $retailPrice;
                }

                $currencyId = $order->priceList?->currency_id;

                $rateToHuf = 1.0;

                if ($currencyId) {
                    $rateKey = $this->exchangeRateKey(
                        (int) $order->season_id,
                        (int) $currencyId,
                    );

                    $rateToHuf = (float) (
                        $exchangeRates->get($rateKey)?->rate_to_huf ?? 1
                    );
                }

                return [
                    'order_id' => (int) $order->id,
                    'season_id' => (int) $order->season_id,
                    'brand_id' => (int) $order->brand_id,
                    'order_sheet_type_id' =>
                        (int) $order->order_sheet_type_id,

                    'partner_code' =>
                        $order->partner?->erp_partner_code ?? '',

                    'partner_name' =>
                        $order->partner?->name ?? '',

                    'address_name' =>
                        $order->partnerAddress?->name
                        ?? $order->partnerAddress?->addrid
                        ?? '',

                    'address' => $this->formatAddress(
                        $order->partnerAddress
                    ),

                    'season' => $order->season?->name ?? '',
                    'brand' => $order->brand?->name ?? '',

                    'type' => app()->getLocale() === 'en'
                        ? (
                            $order->orderSheetType?->name_en
                            ?? $order->orderSheetType?->name_hu
                        )
                        : (
                            $order->orderSheetType?->name_hu
                            ?? $order->orderSheetType?->name_en
                        ),

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

                    'currency' =>
                        $order->priceList?->currency?->symbol
                        ?? $order->priceList?->currency?->code
                        ?? '',

                    'quantity' => $quantity,
                    'wholesale_value' => $wholesaleValue,
                    'retail_value' => $retailValue,

                    'wholesale_value_huf' =>
                        $wholesaleValue * $rateToHuf,

                    'retail_value_huf' =>
                        $retailValue * $rateToHuf,

                    'rate_to_huf' => $rateToHuf,
                ];
            })
            ->values();
    }

    protected function findPrice(
        Collection $prices,
        int $priceListId,
        int $seasonId,
        int $productId,
    ): float {
        $key = $this->priceKey(
            $priceListId,
            $seasonId,
            $productId,
        );

        return (float) ($prices->get($key)?->net_price ?? 0);
    }

    protected function priceKey(
        int $priceListId,
        int $seasonId,
        int $productId,
    ): string {
        return implode(':', [
            $priceListId,
            $seasonId,
            $productId,
        ]);
    }

    protected function exchangeRateKey(
        int $seasonId,
        int $currencyId,
    ): string {
        return $seasonId . ':' . $currencyId;
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