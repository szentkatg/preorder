<?php

namespace App\Filament\Resources\ItemMainGroups\Pages;

use App\Filament\Resources\ItemMainGroups\ItemGroupResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewItemGroup extends ReadOnlyRecord
{
    protected static string $resource = ItemGroupResource::class;
}
