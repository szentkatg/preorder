<?php

namespace App\Filament\Resources\PricingCalculationRows\Tables;

use App\Filament\Support\PricingGrid;
use App\Models\PricingCalculationRow;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;

class DistributorColumns
{
    public static function make(): array
    {
        $columns = [
            TextColumn::make('distributor_discount_percent')->label('Disztr. kedvezmény %')->numeric()->placeholder('-')
                ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => $livewire->distributorRow($record)?->distributor_discount_percent)
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'distributor_discount_percent', 6, true, '')),
            TextColumn::make('distributor_calculated_price')->label('Kalkulált disztr. EUR')->numeric()->placeholder('-')
                ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => $livewire->distributorRow($record)?->calculated_price)
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'distributor_calculated_price')),
            PricingGrid::manualColumn('distributor_manual_price', 'Manuális disztr. EUR'),
            TextColumn::make('distributor_final_price')->label('Végleges disztr. EUR')->numeric()->placeholder('-')
                ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => self::row($record, $livewire)?->final_price)
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'distributor_final_price'))
                ->icon(fn (PricingCalculationRow $record, $livewire) => self::icon($record, $livewire))
                ->color(fn (PricingCalculationRow $record, $livewire) => self::color($record, $livewire))
                ->iconColor(fn (PricingCalculationRow $record, $livewire) => self::color($record, $livewire)),
            TextColumn::make('distributor_net_net_multiplier')->label('Disztr. nettó/nettó szorzó')->numeric()->placeholder('-')
                ->getStateUsing(function (PricingCalculationRow $record, $livewire): ?float {
                    $price = self::row($record, $livewire)?->final_price;
                    $vat = $livewire->wholesaleVatPercent();

                    return (float) $price > 0 && (float) $record->base_price > 0 && $vat !== null
                        ? (float) $record->base_price / (1 + $vat / 100) / (float) $price : null;
                })
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'distributor_net_net_multiplier', 6, true, ''))
                ->extraHeaderAttributes(fn ($livewire) => ['data-pricing-average-multiplier' => 'Átlag: '.($livewire->averageDistributorNetNetMultiplier === null
                    ? '-' : number_format($livewire->averageDistributorNetNetMultiplier, 2, ',', ' '))]),
            IconColumn::make('distributor_requires_review')->label('Disztr. felülvizsgálandó')->boolean()->trueColor('danger')->falseColor('success')->placeholder('-')
                ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => self::row($record, $livewire)?->requires_review),
            TextColumn::make('distributor_status')->label('Disztr. státusz')->badge()->placeholder('-')
                ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => self::row($record, $livewire)?->status)
                ->formatStateUsing(fn ($state) => match ($state) {
                    'calculated' => 'Kalkulált ár', 'reviewed' => 'Átnézett ár', 'approved' => 'Jóváhagyott ár',
                    'modified_approved' => 'Módosított jóváhagyott', 'approved_round_2' => '2. körös jóváhagyott', 'approved_round_3' => '3. körös jóváhagyott', default => $state,
                }),
        ];
        foreach ($columns as $column) {
            $column->extraAttributes(['data-pricing-distributor-column' => true]);
        }

        return $columns;
    }

    private static function row(PricingCalculationRow $record, $livewire): ?PricingCalculationRow
    {
        return $livewire->distributorRow($record);
    }

    private static function icon(PricingCalculationRow $record, $livewire): ?string
    {
        $direction = self::row($record, $livewire)?->finalPriceDifferenceDirection();

        return $direction ? 'heroicon-o-arrow-'.$direction : null;
    }

    private static function color(PricingCalculationRow $record, $livewire): ?string
    {
        return match (self::row($record, $livewire)?->finalPriceDifferenceDirection()) {
            'down' => 'danger', 'up' => 'success', default => null,
        };
    }
}
