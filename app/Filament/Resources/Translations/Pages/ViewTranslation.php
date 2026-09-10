<?php

namespace App\Filament\Resources\Translations\Pages;

use App\Filament\Resources\Translations\TranslationResource;
use App\Filament\Support\Pages\ReadOnlyRecord;

class ViewTranslation extends ReadOnlyRecord
{
    protected static string $resource = TranslationResource::class;
}
