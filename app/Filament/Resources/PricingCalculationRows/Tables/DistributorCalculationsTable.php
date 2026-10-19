<?php

namespace App\Filament\Resources\PricingCalculationRows\Tables;

use App\Filament\Support\Actions\DistributorReviewActions;
use App\Filament\Support\PricingGrid;
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

        return PricingGrid::rows($table)->columns([
            $columns['pricingProject.name'], $columns['product.catalog_group_name_hu'],
            $columns['product.itemMainGroup.name_hu'], $columns['product.model_code'], $columns['product.name_hu'], $columns['color.name_hu'],
            $columns['supplier.erp_partner_code'], $columns['supplier.name'],
            $columns['priceList.code']->label('Forrás nagyker árlista'),
            $columns['base_price']->label('Bruttó kisker EUR')->toggleable(isToggledHiddenByDefault: true),
            $columns['final_price']->label('Forrás nettó nagyker EUR')->icon(null)->color(null)->iconColor(null),
            $columns['requires_review']->label('Nagyker forrás felülvizsgálandó')->toggleable(isToggledHiddenByDefault: true),
            ...DistributorColumns::make(),
            PricingGrid::operationsColumn(),
        ])->filters([
            TernaryFilter::make('distributor_requires_review')->label('Disztribútori felülvizsgálat')
                ->queries(
                    true: fn ($query) => $query->whereRaw(DistributorComparison::sql('distributor_requires_review', $table->getLivewire()->selectedDistributorPriceList?->id).' = 1'),
                    false: fn ($query) => $query->whereRaw(DistributorComparison::sql('distributor_requires_review', $table->getLivewire()->selectedDistributorPriceList?->id).' = 0'),
                    blank: fn ($query) => $query,
                ),
        ])->toolbarActions([DistributorReviewActions::bulk(), $table->getLivewire()->pricingAcceptBulkAction(), $table->getLivewire()->pricingDetailsBulkAction()])
            ->selectable(fn ($livewire) => $livewire::canAccess() && $livewire->selectedDistributorPriceList
                && (Gate::allows('view', new PricingCalculationRow) || ($livewire->pricingWorkspaceIsEditable() && Gate::allows('update', new PricingCalculationRow))))
            ->checkIfRecordIsSelectableUsing(fn (PricingCalculationRow $record, $livewire) => ($row = $livewire->pricingEditableRow($record))
                && (Gate::allows('view', $row) || $livewire->canEditPricingGridRow($record)))
            ->deselectAllRecordsWhenFiltered()
            ->defaultSort('updated_at', 'desc')->reorderableColumns()
            ->defaultPaginationPageOption(100)->paginationPageOptions([50, 100, 250, 500, 'all']);
    }
}
