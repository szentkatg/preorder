<?php

namespace App\Services\Pricing;

use App\Models\PricingCalculationRow;
use DomainException;

class WholesalePriceReviewService extends RetailPriceReviewService
{
    protected function assertEditableCalculation(PricingCalculationRow $stored): void
    {
        if ($stored->price_type !== PricingCalculationRow::TYPE_WHOLESALE
            || ! in_array($stored->currency?->code, WholesaleCalculator::CURRENCIES, true)
            || ! ($stored->pricingProject?->isOpenForPricing() ?? false)) {
            throw new DomainException('Csak nem lezárt árprojekt HUF/EUR nagyker ára módosítható.');
        }
        app(WholesaleCalculator::class)->assertCurrent($stored);
    }
}
