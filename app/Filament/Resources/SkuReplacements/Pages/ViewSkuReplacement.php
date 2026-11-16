<?php

namespace App\Filament\Resources\SkuReplacements\Pages;

use App\Filament\Resources\SkuReplacements\SkuReplacementResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSkuReplacement extends ViewRecord
{
    protected static string $resource = SkuReplacementResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
