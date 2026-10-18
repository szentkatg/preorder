<?php

namespace App\Filament\Support;

use App\Filament\Pages\PricingProductWorkspace;
use App\Models\PricingCalculationRow;
use Illuminate\Support\Facades\Gate;

class ProductPricingNavigation
{
    public static function canEdit(PricingCalculationRow $record): bool
    {
        if (! PricingProductWorkspace::canAccess() || ! ($record->pricingProject?->isOpenForPricing() ?? false)
            || ! $record->product?->active || $record->product->season_id != $record->pricingProject->season_id
            || ! Gate::allows('view', $record) || ! Gate::allows('update', $record->pricingProject)
            || ! Gate::allows('create', PricingCalculationRow::class) || ! Gate::allows('update', new PricingCalculationRow)) {
            return false;
        }

        return true;
    }

    public static function items(PricingCalculationRow $record): array
    {
        return self::canEdit($record) ? [['label' => 'Termékenkénti árkezelés', 'action' => 'productPricing']] : [];
    }
}
