<?php

namespace App\Filament\Resources\Seasons\Pages;

use App\Filament\Resources\Seasons\SeasonResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewSeason extends ReadOnlyRecord
{
    protected static string $resource = SeasonResource::class;
}
