<?php

namespace App\Filament\Resources\Itemmaingroups\Pages;

use App\Filament\Resources\Itemmaingroups\ItemmaingroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditItemmaingroup extends EditRecord
{
    protected static string $resource = ItemmaingroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
