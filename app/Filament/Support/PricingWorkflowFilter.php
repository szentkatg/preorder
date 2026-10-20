<?php

namespace App\Filament\Support;

use App\Filament\Pages\PricingCostCalculations;
use App\Filament\Pages\PricingDistributorCalculations;
use App\Filament\Pages\PricingHufRetailCalculations;
use App\Filament\Pages\PricingProductOverview;
use App\Filament\Pages\PricingWholesaleCalculations;
use App\Services\Pricing\PricingAttentionQuery;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PricingWorkflowFilter
{
    public static function configure(Table $table): void
    {
        $page = $table->getLivewire();
        if (! ($page instanceof PricingCostCalculations || $page instanceof PricingHufRetailCalculations || $page instanceof PricingProductOverview)) {
            return;
        }

        $table->pushFilters([
            SelectFilter::make('workflow_state')->label('Áttekintő szerinti állapot')
                ->placeholder('Összes tétel')->options(['approved' => 'Jóváhagyott / rendben', 'pending' => 'Függő'])
                ->query(fn (Builder $query, array $data, $livewire): Builder => self::apply($query, $data['value'] ?? null, $livewire)),
        ]);
    }

    public static function apply(Builder $query, ?string $state, $page): Builder
    {
        if (! in_array($state, ['approved', 'pending'], true)) {
            return $query;
        }
        $alias = 'workflow_price';
        $related = fn () => DB::table('pricing_calculation_rows as '.$alias)
            ->whereColumn($alias.'.pricing_project_id', 'pricing_calculation_rows.pricing_project_id')
            ->whereColumn($alias.'.product_id', 'pricing_calculation_rows.product_id')
            ->whereColumn($alias.'.color_key', 'pricing_calculation_rows.color_key');

        if ($page instanceof PricingProductOverview) {
            $pending = PricingAttentionQuery::apply($related()->selectRaw('1'), 'pending', $alias);

            return $state === 'pending' ? $query->whereExists($pending) : $query->whereNotExists($pending);
        }
        if ($page instanceof PricingDistributorCalculations) {
            return $query->whereExists(PricingAttentionQuery::apply($related()->selectRaw('1')
                ->where($alias.'.price_type', 'distributor')
                ->whereColumn($alias.'.source_wholesale_row_id', 'pricing_calculation_rows.id')
                ->whereColumn($alias.'.currency_id', 'pricing_calculation_rows.currency_id')
                ->where($alias.'.price_list_id', $page->selectedDistributorPriceList?->id ?? 0), $state, $alias));
        }
        if ($page instanceof PricingHufRetailCalculations && ! ($page instanceof PricingWholesaleCalculations)) {
            // Retail and outlet prices share a visible source row. Match if either
            // price has the selected state; do not mistake the source's approval
            // for the outlet's approval (or hide an approved retail behind a pending outlet).
            $outlets = PricingAttentionQuery::apply($related()->selectRaw('1')
                ->where($alias.'.price_type', 'outlet')
                ->whereColumn($alias.'.source_retail_row_id', 'pricing_calculation_rows.id')
                ->whereColumn($alias.'.currency_id', 'pricing_calculation_rows.currency_id')
                ->whereIn($alias.'.price_list_id', $page->outletPriceLists->modelKeys()), $state, $alias);

            return $query->where(fn (Builder $group) => $group
                ->where(fn (Builder $own) => PricingAttentionQuery::apply($own, $state))
                ->orWhereExists($outlets));
        }

        return PricingAttentionQuery::apply($query, $state);
    }
}
