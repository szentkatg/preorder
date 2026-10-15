<?php

namespace App\Filament\Resources\PricingCalculationRows\Tables;

use App\Filament\Support\Actions\DistributorReviewActions;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\DistributorComparison;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class DistributorCalculationsTable
{
    public static function configure(Table $table): Table
    {
        // Reuse column definitions, never register wholesale write actions on this table:
        // replacing visible actions alone would leave Filament's flat action cache populated.
        $template = WholesaleCalculationsTable::configure(Table::make($table->getLivewire()), 'EUR');
        $columns = $template->getColumns();

        return $table->columns([
            $columns['pricingProject.name'], $columns['product.catalog_group_name_hu'],
            $columns['product.itemMainGroup.name_hu'], $columns['product.model_code'], $columns['product.name_hu'], $columns['color.name_hu'],
            $columns['supplier.erp_partner_code'], $columns['supplier.name'],
            $columns['priceList.code']->label('Forrás nagyker árlista'),
            $columns['base_price']->label('Bruttó kisker EUR')->toggleable(isToggledHiddenByDefault: true),
            $columns['final_price']->label('Forrás nettó nagyker EUR')->icon(null)->color(null)->iconColor(null),
            $columns['requires_review']->label('Nagyker forrás felülvizsgálandó')->toggleable(isToggledHiddenByDefault: true),
            ...DistributorColumns::make(),
        ])->filters([
            TernaryFilter::make('distributor_requires_review')->label('Disztribútori felülvizsgálat')
                ->queries(
                    true: fn ($query) => $query->whereRaw(DistributorComparison::sql('distributor_requires_review', $table->getLivewire()->selectedDistributorPriceList?->id).' = 1'),
                    false: fn ($query) => $query->whereRaw(DistributorComparison::sql('distributor_requires_review', $table->getLivewire()->selectedDistributorPriceList?->id).' = 0'),
                    blank: fn ($query) => $query,
                ),
        ])->recordActions([DistributorReviewActions::group()])
            ->toolbarActions([DistributorReviewActions::bulk()])
            ->selectable(fn ($livewire) => $livewire::canAccess() && $livewire->pricingWorkspaceIsEditable()
                && $livewire->selectedDistributorPriceList && Gate::allows('update', new PricingCalculationRow))
            ->checkIfRecordIsSelectableUsing(fn ($livewire) => $livewire->pricingWorkspaceIsEditable())
            ->deselectAllRecordsWhenFiltered()
            ->defaultSort('updated_at', 'desc')->reorderableColumns()->columnManagerColumns(3)
            ->defaultPaginationPageOption(100)->paginationPageOptions([50, 100, 250, 500, 'all']);
    }
}
