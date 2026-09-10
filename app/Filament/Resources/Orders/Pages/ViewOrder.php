<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewOrder extends ReadOnlyRecord
{
    protected static string $resource = OrderResource::class;
}
