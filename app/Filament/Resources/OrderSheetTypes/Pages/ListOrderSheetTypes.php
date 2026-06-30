<?php

namespace App\Filament\Resources\OrderSheetTypes\Pages;

use App\Filament\Resources\OrderSheetTypes\OrderSheetTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrderSheetTypes extends ListRecords
{
    protected static string $resource = OrderSheetTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
