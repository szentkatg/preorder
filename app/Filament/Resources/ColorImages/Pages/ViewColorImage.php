<?php

namespace App\Filament\Resources\ColorImages\Pages;

use App\Filament\Resources\ColorImages\ColorImageResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewColorImage extends ReadOnlyRecord
{
    protected static string $resource = ColorImageResource::class;
}
