<?php

namespace App\Filament\Resources\Skus\Pages;

use App\Filament\Resources\Skus\SkuResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewSku extends ReadOnlyRecord
{
    protected static string $resource = SkuResource::class;
}
