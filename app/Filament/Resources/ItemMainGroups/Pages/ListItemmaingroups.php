<?php

namespace App\Filament\Resources\Itemmaingroups\Pages;

use App\Filament\Resources\Itemmaingroups\ItemmaingroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListItemmaingroups extends ListRecords
{
    protected static string $resource = ItemmaingroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
