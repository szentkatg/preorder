<?php

namespace App\Filament\Resources\Catalogs\Pages;

use App\Filament\Resources\Catalogs\CatalogResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewCatalog extends ReadOnlyRecord
{
    protected static string $resource = CatalogResource::class;
}
