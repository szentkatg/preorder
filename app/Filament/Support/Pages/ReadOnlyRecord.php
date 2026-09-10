<?php

namespace App\Filament\Support\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

abstract class ReadOnlyRecord extends ViewRecord
{
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
