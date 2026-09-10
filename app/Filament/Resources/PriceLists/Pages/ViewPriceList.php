<?php

namespace App\Filament\Resources\PriceLists\Pages;

use App\Filament\Resources\PriceLists\PriceListResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewPriceList extends ReadOnlyRecord
{
    protected static string $resource = PriceListResource::class;
}
