<?php

namespace App\Filament\Resources\PartnerAddresses\Pages;

use App\Filament\Resources\PartnerAddresses\PartnerAddressResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPartnerAddress extends EditRecord
{
    protected static string $resource = PartnerAddressResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
