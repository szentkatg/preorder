<?php

namespace App\Filament\Support\Concerns;

use Illuminate\Database\Eloquent\Model;

trait LocksClosedPricingProject
{
    public static function canEdit(Model $record): bool
    {
        return ($record->pricingProject?->isOpenForPricing() ?? false) && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return ($record->pricingProject?->isOpenForPricing() ?? false) && parent::canDelete($record);
    }
}
