<?php

namespace App\Filament\Resources\PriceListItems\Pages;

use App\Filament\Resources\PriceListItems\PriceListItemResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewPriceListItem extends ReadOnlyRecord
{
    protected static string $resource = PriceListItemResource::class;
}
