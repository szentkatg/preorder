<?php

namespace App\Filament\Resources\OrderSheetTypes\Pages;

use App\Filament\Resources\OrderSheetTypes\OrderSheetTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOrderSheetType extends EditRecord
{
    protected static string $resource = OrderSheetTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
