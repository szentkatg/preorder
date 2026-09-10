<?php

namespace App\Filament\Resources\Currencies\Pages;

use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewCurrency extends ReadOnlyRecord
{
    protected static string $resource = CurrencyResource::class;
}
