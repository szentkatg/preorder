<?php

namespace App\Filament\Resources\SizeRanges\Pages;

use App\Filament\Resources\SizeRanges\SizeRangeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSizeRanges extends ListRecords
{
    protected static string $resource = SizeRangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
