<?php

namespace App\Filament\Resources\ProductPurchasePrices\Pages;

use App\Filament\Resources\ProductPurchasePrices\ProductPurchasePriceResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewProductPurchasePrice extends ReadOnlyRecord
{
    protected static string $resource = ProductPurchasePriceResource::class;
}
