<?php

namespace App\Filament\Resources\PricingProjects\Pages;

use App\Filament\Resources\PricingProjects\PricingProjectResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewPricingProject extends ReadOnlyRecord
{
    protected static string $resource = PricingProjectResource::class;
}
