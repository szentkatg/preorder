<?php

namespace App\Filament\Resources\PricingRoundingRules\Pages;

use App\Filament\Resources\PricingRoundingRules\PricingRoundingRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPricingRoundingRules extends ListRecords
{
    protected static string $resource = PricingRoundingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
