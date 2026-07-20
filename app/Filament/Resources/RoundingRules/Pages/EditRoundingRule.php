<?php

namespace App\Filament\Resources\RoundingRules\Pages;

use App\Filament\Resources\RoundingRules\RoundingRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRoundingRule extends EditRecord
{
    protected static string $resource = RoundingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}