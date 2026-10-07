<?php

namespace App\Filament\Resources\PricingCalculationRows\Tables;

use App\Models\PricingCalculationRow;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PricingCalculationRowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pricingProject.name')->label('Árprojekt'),
                TextColumn::make('product.catalog_group_name_hu')->label('Katalógus csoport'),
                TextColumn::make('product.itemMainGroup.name_hu')->label('Főcsoport'),
                TextColumn::make('product.model_code')->label('Modell kód'),
                TextColumn::make('product.name_hu')->label('Modell név'),
                TextColumn::make('product.material_composition')->label('Anyagösszetétel'),
                TextColumn::make('color.name_hu')->label('Szín'),
                TextColumn::make('supplier.short_name')->label('Beszállító'),
                TextColumn::make('supplier.country_code')->label('Besz. ország'),
                TextColumn::make('purchase_price')->label('Besz. ár')->numeric(decimalPlaces: 4),
                TextColumn::make('purchaseCurrency.code')->label('Besz. deviza'),
                TextColumn::make('exchange_rate')->label('Árfolyam')->numeric(decimalPlaces: 4),
                TextColumn::make('shipping_cost_percent')->label('Száll. %')->numeric(decimalPlaces: 2),
                TextColumn::make('customs_percent')->label('Vám %')->numeric(decimalPlaces: 2),
                TextColumn::make('candidate_count')->label('Vizsgált aktív árak'),
                TextColumn::make('priceList.code')->label('Árlista'),
                TextColumn::make('price_type')->label('Ártípus')->badge(),
                TextColumn::make('currency.code')->label('Pénznem'),
                TextColumn::make('calculated_price')->label('Kalkulált ár')->numeric(decimalPlaces: 2),
                TextColumn::make('manual_price')->label('Manuális ár')->numeric(decimalPlaces: 2),
                TextColumn::make('final_price')->label('Végleges ár')->numeric(decimalPlaces: 2),
                TextColumn::make('status')->label('Státusz')->badge(),
            ])
            ->filters([
                SelectFilter::make('pricing_project_id')
                    ->label('Árprojekt')
                    ->relationship('pricingProject', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('price_type')
                    ->label('Ártípus')
                    ->options([
                        PricingCalculationRow::TYPE_COST => 'Bekerülési érték',
                        PricingCalculationRow::TYPE_RETAIL => 'Kisker',
                        PricingCalculationRow::TYPE_WHOLESALE => 'Nagyker',
                        PricingCalculationRow::TYPE_DISTRIBUTOR => 'Disztribútor',
                    ])
                    ->default(PricingCalculationRow::TYPE_COST),

                SelectFilter::make('status')
                    ->label('Státusz')
                    ->options([
                        PricingCalculationRow::STATUS_CALCULATED => 'Kalkulált ár',
                        PricingCalculationRow::STATUS_REVIEWED => 'Átnézett ár',
                        PricingCalculationRow::STATUS_APPROVED => 'Jóváhagyott ár',
                        PricingCalculationRow::STATUS_MODIFIED_APPROVED => 'Módosított jóváhagyott ár',
                        PricingCalculationRow::STATUS_APPROVED_ROUND_2 => '2. körös jóváhagyott',
                        PricingCalculationRow::STATUS_APPROVED_ROUND_3 => '3. körös jóváhagyott',
                    ]),
            ])
            ->defaultSort('updated_at', 'desc')
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
