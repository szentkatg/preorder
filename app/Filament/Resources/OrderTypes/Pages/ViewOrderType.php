<?php

namespace App\Filament\Resources\OrderTypes\Pages;

use App\Filament\Resources\OrderTypes\OrderTypeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOrderType extends ViewRecord
{
    protected static string $resource = OrderTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
