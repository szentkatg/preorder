<?php

namespace App\Filament\Resources\ItemMainGroups\Pages;

use App\Filament\Resources\ItemMainGroups\ItemGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListItemGroups extends ListRecords
{
    protected static string $resource = ItemGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
