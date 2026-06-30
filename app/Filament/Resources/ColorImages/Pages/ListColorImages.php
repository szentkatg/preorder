<?php

namespace App\Filament\Resources\ColorImages\Pages;

use App\Filament\Resources\ColorImages\ColorImageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListColorImages extends ListRecords
{
    protected static string $resource = ColorImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
