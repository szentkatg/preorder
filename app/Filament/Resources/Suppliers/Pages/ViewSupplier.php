<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewSupplier extends ReadOnlyRecord
{
    protected static string $resource = SupplierResource::class;
}
