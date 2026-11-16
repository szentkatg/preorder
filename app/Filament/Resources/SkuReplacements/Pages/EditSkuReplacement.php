<?php

namespace App\Filament\Resources\SkuReplacements\Pages;

use App\Filament\Resources\SkuReplacements\SkuReplacementResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSkuReplacement extends EditRecord
{
    protected static string $resource = SkuReplacementResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return SkuReplacementResource::saveWithFormErrors(fn () => parent::handleRecordUpdate($record, $data));
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
