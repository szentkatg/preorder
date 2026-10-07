<?php

namespace App\Filament\Resources\PricingCalculationErrors\Pages;

use App\Filament\Resources\PricingCalculationErrors\PricingCalculationErrorResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewPricingCalculationError extends ReadOnlyRecord
{
    protected static string $resource = PricingCalculationErrorResource::class;
}
