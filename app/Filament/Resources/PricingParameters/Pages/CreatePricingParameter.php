<?php

namespace App\Filament\Resources\PricingParameters\Pages;

use App\Filament\Resources\PricingParameters\PricingParameterResource;
use App\Filament\Support\Concerns\GuardsPricingProjectWrites;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingParameter extends CreateRecord
{
    use GuardsPricingProjectWrites;

    protected static string $resource = PricingParameterResource::class;
}
