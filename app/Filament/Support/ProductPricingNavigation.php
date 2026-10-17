<?php

namespace App\Filament\Support;

use App\Filament\Pages\PricingProductWorkspace;
use App\Models\PricingCalculationRow;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Gate;

class ProductPricingNavigation
{
    public static function items(PricingCalculationRow $record): array
    {
        if (! PricingProductWorkspace::canAccess() || ! ($record->pricingProject?->isOpenForPricing() ?? false)
            || ! $record->product?->active || $record->product->season_id != $record->pricingProject->season_id
            || ! Gate::allows('view', $record) || ! Gate::allows('update', $record->pricingProject)
            || ! Gate::allows('create', PricingCalculationRow::class) || ! Gate::allows('update', new PricingCalculationRow)) {
            return [];
        }

        return [['label' => 'Termékenkénti árkezelés', 'url' => PricingProductWorkspace::getUrl([
            'project' => $record->pricing_project_id, 'product' => $record->product_id,
        ])]];
    }

    public static function action(): Action
    {
        return Action::make('productPricing')->label('Termékenkénti árkezelés')->icon('heroicon-o-adjustments-horizontal')
            ->visible(fn (PricingCalculationRow $record) => self::items($record) !== [])
            ->url(fn (PricingCalculationRow $record) => self::items($record)[0]['url'] ?? null);
    }
}
