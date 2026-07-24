<?php

namespace App\Filament\Resources\ItemMainGroups\Pages;

use App\Filament\Resources\ItemMainGroups\ItemGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditItemGroup extends EditRecord
{
    protected static string $resource = ItemGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
