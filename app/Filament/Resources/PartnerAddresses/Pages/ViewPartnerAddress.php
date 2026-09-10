<?php

namespace App\Filament\Resources\PartnerAddresses\Pages;

use App\Filament\Resources\PartnerAddresses\PartnerAddressResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewPartnerAddress extends ReadOnlyRecord
{
    protected static string $resource = PartnerAddressResource::class;
}
