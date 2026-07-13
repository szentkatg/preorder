<?php

namespace App\Filament\Resources\ProductPurchasePrices\Pages;

use App\Filament\Resources\ProductPurchasePrices\ProductPurchasePriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductPurchasePrices extends ListRecords
{
    protected static string $resource =
        ProductPurchasePriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}