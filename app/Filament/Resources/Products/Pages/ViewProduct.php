<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewProduct extends ReadOnlyRecord
{
    protected static string $resource = ProductResource::class;
}
