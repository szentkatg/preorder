<?php

namespace App\Filament\Resources\PartnerUsers\Pages;

use App\Filament\Resources\PartnerUsers\PartnerUserResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewPartnerUser extends ReadOnlyRecord
{
    protected static string $resource = PartnerUserResource::class;
}
