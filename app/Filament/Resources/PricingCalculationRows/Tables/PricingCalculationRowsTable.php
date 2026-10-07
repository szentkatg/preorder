<?php

namespace App\Filament\Resources\PricingCalculationRows\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PricingCalculationRowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pricingProject.name')->label('Árprojekt'),
                TextColumn::make('product.catalog_group_name_hu')->label('Katalógus csoport'),
                TextColumn::make('product.model_code')->label('Modell kód'),
                TextColumn::make('product.name_hu')->label('Modell név'),
                TextColumn::make('color.name_hu')->label('Szín'),
                TextColumn::make('priceList.code')->label('Árlista'),
                TextColumn::make('price_type')->label('Ártípus')->badge(),
                TextColumn::make('currency.code')->label('Pénznem'),
                TextColumn::make('calculated_price')->label('Kalkulált ár')->numeric(decimalPlaces: 2),
                TextColumn::make('manual_price')->label('Manuális ár')->numeric(decimalPlaces: 2),
                TextColumn::make('final_price')->label('Végleges ár')->numeric(decimalPlaces: 2),
                TextColumn::make('status')->label('Státusz')->badge(),
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
