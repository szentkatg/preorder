<?php

namespace App\Filament\Resources\OrderSheetTypes\Pages;

use App\Filament\Resources\OrderSheetTypes\OrderSheetTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrderSheetType extends CreateRecord
{
    protected static string $resource = OrderSheetTypeResource::class;
}
