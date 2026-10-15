<?php

namespace App\Services\Pricing;

use App\Models\PricingCalculationRow;
use DomainException;

class DistributorPriceReviewService extends RetailPriceReviewService
{
    protected function assertEditableCalculation(PricingCalculationRow $stored): void
    {
        if ($stored->price_type !== PricingCalculationRow::TYPE_DISTRIBUTOR || $stored->currency?->code !== 'EUR'
            || ! ($stored->pricingProject?->isOpenForPricing() ?? false)) {
            throw new DomainException('Csak nem lezárt árprojekt EUR disztribútori ára módosítható.');
        }
        app(DistributorCalculator::class)->assertCurrent($stored);
    }
}
