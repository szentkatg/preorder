<?php

namespace App\Filament\Resources\PartnerUsers\Pages;

use App\Filament\Resources\PartnerUsers\PartnerUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePartnerUser extends CreateRecord
{
    protected static string $resource = PartnerUserResource::class;
}
