<?php

namespace App\Filament\Resources\PricingCalculationRows\Pages;

use App\Filament\Resources\PricingCalculationRows\PricingCalculationRowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPricingCalculationRows extends ListRecords
{
    protected static string $resource = PricingCalculationRowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
