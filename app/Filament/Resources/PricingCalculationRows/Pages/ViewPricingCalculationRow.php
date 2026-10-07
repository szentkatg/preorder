<?php

namespace App\Filament\Resources\PricingCalculationRows\Pages;

use App\Filament\Resources\PricingCalculationRows\PricingCalculationRowResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewPricingCalculationRow extends ReadOnlyRecord
{
    protected static string $resource = PricingCalculationRowResource::class;
}
