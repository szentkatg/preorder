<?php

namespace App\Filament\Resources\PartnerAddresses\Pages;

use App\Filament\Resources\PartnerAddresses\PartnerAddressResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPartnerAddresses extends ListRecords
{
    protected static string $resource = PartnerAddressResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
