<?php

namespace App\Filament\Resources\Colors\Pages;

use App\Filament\Resources\Colors\ColorResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewColor extends ReadOnlyRecord
{
    protected static string $resource = ColorResource::class;
}
