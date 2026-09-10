<?php

namespace App\Filament\Resources\Languages\Pages;

use App\Filament\Resources\Languages\LanguageResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewLanguage extends ReadOnlyRecord
{
    protected static string $resource = LanguageResource::class;
}
