<?php

namespace App\Filament\Resources\Brands\Pages;

use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewBrand extends ReadOnlyRecord
{
    protected static string $resource = BrandResource::class;
}
