<?php

namespace App\Filament\Resources\PricingCalculationRows\Tables;

use App\Models\PricingCalculationRow;
use App\Services\Pricing\WholesalePriceReviewService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WholesaleCalculationsTable
{
    public static function configure(Table $table, string $currency): Table
    {
        $table = HufRetailCalculationsTable::configure($table, $currency, WholesalePriceReviewService::class);
        $shared = $table->getColumns();
        foreach ($table->getToolbarActions() as $action) {
            if ($action->getName() === 'approveRetailBulk') {
                $action->label('Kijelölt nagyker árak jóváhagyása');
            }
        }

        return $table->columns([
            $shared['pricingProject.name'], $shared['priceList.code'], $shared['product.catalog_group_name_hu'],
            $shared['product.itemMainGroup.name'], $shared['product.model_code'], $shared['product.name_hu'], $shared['color.name_hu'],
            $shared['supplier.erp_partner_code'], $shared['supplier.name'],
            TextColumn::make('sourceRetailRow.priceList.code')->label('Forrás kisker árlista')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('base_price')->label('Bruttó kisker '.$currency)->numeric()
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'base_price')),
            TextColumn::make('calculation_multiplier')->label('Kalkulációs nagyker szorzó')->numeric()
                ->getStateUsing(fn (PricingCalculationRow $record) => $record->multiplier === null ? null : (float) $record->multiplier)
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'calculation_multiplier', 6, true, '')),
            TextColumn::make('wholesale_multiplier')->label('Tényleges nagyker szorzó')->numeric()->placeholder('-')
                ->getStateUsing(fn (PricingCalculationRow $record) => $record->effective_wholesale_multiplier)
                ->extraHeaderAttributes(fn ($livewire) => self::averageAttributes($livewire->averageRetailMultiplier))
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'wholesale_multiplier', 6, true, ''))
                ->tooltip('Bruttó kisker ár ÷ végleges nettó nagyker ár. A kerekítés és a manuális ár hatásával.'),
            TextColumn::make('net_net_multiplier')->label('Nettó/nettó szorzó')->numeric()->placeholder('-')
                ->getStateUsing(fn (PricingCalculationRow $record, $livewire) => $record->effective_wholesale_multiplier !== null && $livewire->wholesaleVatPercent() !== null
                    ? $record->effective_wholesale_multiplier / (1 + $livewire->wholesaleVatPercent() / 100) : null)
                ->extraHeaderAttributes(fn ($livewire) => self::averageAttributes($livewire->averageNetNetMultiplier))
                ->formatStateUsing(fn ($state, $livewire) => $livewire->formatPricingDecimal($state, 'net_net_multiplier', 6, true, ''))
                ->tooltip('Bruttó kisker ár ÷ (1 + ÁFA% / 100) ÷ végleges nettó nagyker ár. Az ÁFA csak összehasonlító beállítás.'),
            $shared['unrounded_price']->label('Kerekítés előtti nettó '.$currency),
            $shared['pricingRoundingRule.name'], $shared['calculated_price']->label('Kalkulált nettó nagyker '.$currency),
            $shared['manual_price']->label('Manuális nettó '.$currency),
            $shared['final_price']->label('Végleges nettó nagyker '.$currency), $shared['requires_review'], $shared['status'],
            $shared['pricing_operations'],
        ]);
    }

    private static function averageAttributes(?float $value): array
    {
        return ['data-pricing-average-multiplier' => 'Átlag: '.($value === null ? '-' : number_format($value, 2, ',', ' ')),
            'aria-description' => 'Értékkel súlyozott szorzó: osztandók összege / osztók összege a teljes szűrt találati halmazon'];
    }
}
