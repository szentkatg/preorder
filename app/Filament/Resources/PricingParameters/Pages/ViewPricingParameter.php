<?php

namespace App\Filament\Resources\PricingParameters\Pages;

use App\Filament\Resources\PricingParameters\PricingParameterResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewPricingParameter extends ReadOnlyRecord
{
    protected static string $resource = PricingParameterResource::class;
}
