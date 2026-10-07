<?php

namespace App\Filament\Resources\PricingParameters\Pages;

use App\Filament\Resources\PricingParameters\PricingParameterResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPricingParameter extends EditRecord
{
    protected static string $resource = PricingParameterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
