<?php

namespace App\Filament\Resources\PricingRoundingRules\Pages;

use App\Filament\Resources\PricingRoundingRules\PricingRoundingRuleResource;
use App\Filament\Support\Concerns\GuardsPricingProjectWrites;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPricingRoundingRule extends EditRecord
{
    use GuardsPricingProjectWrites;

    protected static string $resource = PricingRoundingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
