<?php

namespace App\Filament\Resources\PartnerUsers\Pages;

use App\Filament\Resources\PartnerUsers\PartnerUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPartnerUsers extends ListRecords
{
    protected static string $resource = PartnerUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
