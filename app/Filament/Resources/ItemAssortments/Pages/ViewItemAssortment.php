<?php

namespace App\Filament\Resources\ItemAssortments\Pages;

use App\Filament\Resources\ItemAssortments\ItemAssortmentResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewItemAssortment extends ReadOnlyRecord
{
    protected static string $resource = ItemAssortmentResource::class;
}
