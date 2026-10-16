<?php

namespace App\Filament\Resources\PricingCalculationRows\Pages;

use App\Filament\Resources\PricingCalculationRows\PricingCalculationRowResource;
use App\Filament\Support\Concerns\GuardsPricingProjectWrites;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPricingCalculationRow extends EditRecord
{
    use GuardsPricingProjectWrites;

    protected static string $resource = PricingCalculationRowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
