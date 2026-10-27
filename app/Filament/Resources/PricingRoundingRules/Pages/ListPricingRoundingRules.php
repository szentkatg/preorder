<?php

namespace App\Filament\Resources\PricingRoundingRules\Pages;

use App\Filament\Resources\PricingRoundingRules\PricingRoundingRuleResource;
use App\Filament\Support\PricingConfigurationListRecords;
use Filament\Actions\CreateAction;

class ListPricingRoundingRules extends PricingConfigurationListRecords
{
    protected static string $resource = PricingRoundingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
