<?php

namespace App\Filament\Resources\RoundingRules\Pages;

use App\Filament\Resources\RoundingRules\RoundingRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoundingRules extends ListRecords
{
    protected static string $resource = RoundingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}