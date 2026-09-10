<?php

namespace App\Filament\Resources\ExchangeRates\Pages;

use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewExchangeRate extends ReadOnlyRecord
{
    protected static string $resource = ExchangeRateResource::class;
}
