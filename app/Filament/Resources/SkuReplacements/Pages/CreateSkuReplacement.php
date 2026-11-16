<?php

namespace App\Filament\Resources\SkuReplacements\Pages;

use App\Filament\Resources\SkuReplacements\SkuReplacementResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSkuReplacement extends CreateRecord
{
    protected static string $resource = SkuReplacementResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return SkuReplacementResource::saveWithFormErrors(fn () => parent::handleRecordCreation($data));
    }
}
