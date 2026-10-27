<?php

namespace App\Filament\Resources\PricingProjects\Pages;

use App\Filament\Resources\PricingProjects\PricingProjectResource;
use App\Filament\Support\PricingConfigurationListRecords;
use Filament\Actions\CreateAction;

class ListPricingProjects extends PricingConfigurationListRecords
{
    protected static string $resource = PricingProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
