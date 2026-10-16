<?php

namespace App\Filament\Support;

use App\Models\PricingCalculationRow;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class PricingGrid
{
    public static function manualColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)->label($label)->numeric()->placeholder('—')
            ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => $livewire->pricingEditableRow($record)?->manual_price)
            ->formatStateUsing(fn ($state) => str_replace('.', ',', self::inputValue($state)))
            ->icon(fn (PricingCalculationRow $record, $livewire) => ($direction = $livewire->pricingEditableRow($record)?->finalPriceDifferenceDirection())
                ? 'heroicon-o-arrow-'.$direction : null)
            ->color(fn (PricingCalculationRow $record, $livewire) => match ($livewire->pricingEditableRow($record)?->finalPriceDifferenceDirection()) {
                'down' => 'danger', 'up' => 'success', default => null,
            })
            ->extraAttributes(fn (?PricingCalculationRow $record, $livewire) => ['class' => 'pricing-retail-manual-'.($record ? ($livewire->pricingEditableRow($record)?->finalPriceDifferenceDirection() ?? 'equal') : 'equal')])
            ->extraCellAttributes(fn (PricingCalculationRow $record, $livewire) => [
                'data-pricing-editable' => $livewire->canEditPricingGridRow($record) ? 'true' : 'false',
                'data-pricing-record-id' => $record->id,
                'data-pricing-value' => self::inputValue($livewire->pricingEditableRow($record)?->manual_price),
                'data-pricing-label' => $label,
                'tabindex' => 0,
                'title' => $livewire->canEditPricingGridRow($record)
                    ? 'Dupla kattintás vagy Enter/F2: szerkesztés. Üres érték: kalkulált ár visszaállítása. Esc: mégse.' : 'Csak olvasható érték.',
            ]);
    }

    public static function operationsColumn(): ViewColumn
    {
        return ViewColumn::make('pricing_operations')->label('Műveletek')->view('filament.pricing.row-operations')->width('3rem');
    }

    public static function rows(Table $table): Table
    {
        return $table->recordActions([])->recordAction(null)->recordUrl(null)
            ->recordClasses(fn (PricingCalculationRow $record, $livewire) => [
                'pricing-price-row', 'pricing-price-row-id-'.$record->id,
                ($row = $livewire->pricingEditableRow($record)) && Gate::allows('view', $row) ? 'pricing-price-details-enabled' : '',
                'pricing-needs-attention' => self::needsAttention($record, $livewire),
            ]);
    }

    public static function needsAttention(PricingCalculationRow $record, $livewire): bool
    {
        // On the distributor page the table record is the wholesale source;
        // approval belongs to the selected distributor row, not that source.
        $row = $livewire->pricingEditableRow($record);
        if ($row?->needsPricingAttention()) {
            return true;
        }

        if (! $livewire->supportsOutletPricing()) {
            return false;
        }

        foreach ($livewire->outletPriceLists as $list) {
            if (OutletColumns::row($record, $list->id)?->needsPricingAttention()) {
                return true;
            }
        }

        return false;
    }

    public static function inputValue(mixed $value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }
}
