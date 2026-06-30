<?php

namespace App\Filament\Resources\PartnerUsers\Pages;

use App\Filament\Resources\PartnerUsers\PartnerUserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPartnerUser extends EditRecord
{
    protected static string $resource = PartnerUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
