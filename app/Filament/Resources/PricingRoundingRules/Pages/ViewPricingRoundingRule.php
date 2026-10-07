<?php

namespace App\Filament\Resources\PricingRoundingRules\Pages;

use App\Filament\Resources\PricingRoundingRules\PricingRoundingRuleResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewPricingRoundingRule extends ReadOnlyRecord
{
    protected static string $resource = PricingRoundingRuleResource::class;
}
