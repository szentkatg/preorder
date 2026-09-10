<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Filament\Resources\Partners\PartnerResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewPartner extends ReadOnlyRecord
{
    protected static string $resource = PartnerResource::class;
}
