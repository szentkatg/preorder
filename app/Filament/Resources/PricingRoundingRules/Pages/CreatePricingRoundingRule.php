<?php

namespace App\Filament\Resources\PricingRoundingRules\Pages;

use App\Filament\Resources\PricingRoundingRules\PricingRoundingRuleResource;
use App\Filament\Support\Concerns\GuardsPricingProjectWrites;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingRoundingRule extends CreateRecord
{
    use GuardsPricingProjectWrites;

    protected static string $resource = PricingRoundingRuleResource::class;
}
