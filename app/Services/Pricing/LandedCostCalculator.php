<?php

namespace App\Services\Pricing;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\PricingParameter;
use App\Models\PricingProject;
use App\Models\ProductPurchasePrice;
use RuntimeException;

class LandedCostCalculator
{
    public function __construct(
        private readonly PricingParameterResolver $parameterResolver,
    ) {}

    /**
     * @return array{
     *     purchase_price: float,
     *     purchase_currency: string|null,
     *     exchange_rate: float,
     *     shipping_cost_percent: float,
     *     customs_percent: float,
     *     landed_cost_huf: float
     * }
     */
    public function calculate(PricingProject $project, ProductPurchasePrice $purchasePrice): array
    {
        $purchasePrice->loadMissing(['product', 'supplier', 'currency']);

        $product = $purchasePrice->product;
        $currency = $purchasePrice->currency;

        if (! $product || ! $currency) {
            throw new RuntimeException('A beszerzési árhoz termék és pénznem szükséges.');
        }

        $exchangeRate = $this->exchangeRateToHuf($project, $currency);
        $supplierCountryCode = $purchasePrice->supplier?->country_code;

        $shippingCostPercent = (float) (
            $this->parameterResolver->resolve(
                $project->id,
                PricingParameter::TYPE_SHIPPING_COST_PERCENT,
                $product,
                $supplierCountryCode,
                currencyId: $currency->id,
            )?->value ?? 0
        );

        $customsPercent = (float) (
            $this->parameterResolver->resolve(
                $project->id,
                PricingParameter::TYPE_CUSTOMS_PERCENT,
                $product,
                $supplierCountryCode,
                currencyId: $currency->id,
            )?->value ?? 0
        );

        $landedCostHuf = (float) $purchasePrice->purchase_price
            * $exchangeRate
            * (1 + $shippingCostPercent / 100)
            * (1 + $customsPercent / 100);

        return [
            'purchase_price' => (float) $purchasePrice->purchase_price,
            'purchase_currency' => $currency->code,
            'exchange_rate' => $exchangeRate,
            'shipping_cost_percent' => $shippingCostPercent,
            'customs_percent' => $customsPercent,
            'landed_cost_huf' => $landedCostHuf,
        ];
    }

    private function exchangeRateToHuf(PricingProject $project, Currency $currency): float
    {
        if (strtoupper((string) $currency->code) === 'HUF') {
            return 1.0;
        }

        $rate = ExchangeRate::query()
            ->where('season_id', $project->season_id)
            ->where('currency_id', $currency->id)
            ->where('active', true)
            ->value('rate_to_huf');

        if ($rate === null) {
            throw new RuntimeException("Hiányzó árfolyam: {$currency->code}");
        }

        return (float) $rate;
    }
}
