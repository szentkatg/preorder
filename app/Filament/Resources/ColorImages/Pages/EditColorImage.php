<?php

namespace App\Filament\Resources\ColorImages\Pages;

use App\Filament\Resources\ColorImages\ColorImageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditColorImage extends EditRecord
{
    protected static string $resource = ColorImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
