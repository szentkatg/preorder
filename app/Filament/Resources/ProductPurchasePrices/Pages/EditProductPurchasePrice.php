<?php

namespace App\Filament\Resources\ProductPurchasePrices\Pages;

use App\Filament\Resources\ProductPurchasePrices\ProductPurchasePriceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductPurchasePrice extends EditRecord
{
    protected static string $resource =
        ProductPurchasePriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}