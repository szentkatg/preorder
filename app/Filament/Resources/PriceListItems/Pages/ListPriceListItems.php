<?php

namespace App\Filament\Resources\PriceListItems\Pages;

use App\Filament\Resources\PriceListItems\PriceListItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPriceListItems extends ListRecords
{
    protected static string $resource = PriceListItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
