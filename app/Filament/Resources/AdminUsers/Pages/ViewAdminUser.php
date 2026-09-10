<?php

namespace App\Filament\Resources\AdminUsers\Pages;

use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewAdminUser extends ReadOnlyRecord
{
    protected static string $resource = AdminUserResource::class;
}
