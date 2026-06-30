<?php

namespace App\Filament\Resources\SizeRanges\Pages;

use App\Filament\Resources\SizeRanges\SizeRangeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSizeRange extends EditRecord
{
    protected static string $resource = SizeRangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
