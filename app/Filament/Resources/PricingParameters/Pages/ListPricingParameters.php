<?php

namespace App\Filament\Resources\PricingParameters\Pages;

use App\Filament\Resources\PricingParameters\PricingParameterResource;
use App\Filament\Support\PricingConfigurationListRecords;
use Filament\Actions\CreateAction;

class ListPricingParameters extends PricingConfigurationListRecords
{
    protected static string $resource = PricingParameterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
