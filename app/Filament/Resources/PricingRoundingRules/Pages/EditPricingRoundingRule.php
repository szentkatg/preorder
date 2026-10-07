<?php

namespace App\Filament\Resources\PricingRoundingRules\Pages;

use App\Filament\Resources\PricingRoundingRules\PricingRoundingRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPricingRoundingRule extends EditRecord
{
    protected static string $resource = PricingRoundingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
