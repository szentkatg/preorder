<?php

namespace App\Filament\Resources\ItemAssortments\Pages;

use App\Filament\Resources\ItemAssortments\ItemAssortmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditItemAssortment extends EditRecord
{
    protected static string $resource = ItemAssortmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
