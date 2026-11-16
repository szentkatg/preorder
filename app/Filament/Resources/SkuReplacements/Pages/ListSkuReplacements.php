<?php

namespace App\Filament\Resources\SkuReplacements\Pages;

use App\Filament\Resources\SkuReplacements\SkuReplacementResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSkuReplacements extends ListRecords
{
    protected static string $resource = SkuReplacementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make(), Action::make('importExcel')->label('Importálás Excelből')->icon('heroicon-o-arrow-up-tray')
            ->visible(fn (): bool => SkuReplacementResource::canCreate())
            ->url(SkuReplacementResource::getUrl('import'))];
    }
}
