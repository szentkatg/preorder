<?php

namespace App\Filament\Resources\OrderSheetTypes\Pages;

use App\Filament\Resources\OrderSheetTypes\OrderSheetTypeResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewOrderSheetType extends ReadOnlyRecord
{
    protected static string $resource = OrderSheetTypeResource::class;
}
