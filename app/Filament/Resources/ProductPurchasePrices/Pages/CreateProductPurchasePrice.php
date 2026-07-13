<?php

namespace App\Filament\Resources\ProductPurchasePrices\Pages;

use App\Filament\Resources\ProductPurchasePrices\ProductPurchasePriceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductPurchasePrice extends CreateRecord
{
    protected static string $resource =
        ProductPurchasePriceResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', [
            'record' => $this->getRecord(),
        ]);
    }
}