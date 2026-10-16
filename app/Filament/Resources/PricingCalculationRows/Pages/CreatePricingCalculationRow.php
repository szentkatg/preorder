<?php

namespace App\Filament\Resources\PricingCalculationRows\Pages;

use App\Filament\Resources\PricingCalculationRows\PricingCalculationRowResource;
use App\Filament\Support\Concerns\GuardsPricingProjectWrites;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingCalculationRow extends CreateRecord
{
    use GuardsPricingProjectWrites;

    protected static string $resource = PricingCalculationRowResource::class;
}
