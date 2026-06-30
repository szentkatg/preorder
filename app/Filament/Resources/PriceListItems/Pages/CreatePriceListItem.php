<?php

namespace App\Filament\Resources\PriceListItems\Pages;

use App\Filament\Resources\PriceListItems\PriceListItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePriceListItem extends CreateRecord
{
    protected static string $resource = PriceListItemResource::class;
}
