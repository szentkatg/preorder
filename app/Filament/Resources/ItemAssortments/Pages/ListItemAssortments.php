<?php

namespace App\Filament\Resources\ItemAssortments\Pages;

use App\Filament\Resources\ItemAssortments\ItemAssortmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListItemAssortments extends ListRecords
{
    protected static string $resource = ItemAssortmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
