<?php

namespace App\Filament\Resources\PriceListItems\Pages;

use App\Filament\Resources\PriceListItems\PriceListItemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPriceListItem extends EditRecord
{
    protected static string $resource = PriceListItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
