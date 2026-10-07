<?php

namespace App\Filament\Resources\PricingParameters\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PricingParametersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pricingProject.name')->label('Árprojekt'),
                TextColumn::make('parameter_type')->label('Paraméter')->badge(),
                TextColumn::make('scope_type')->label('Szűkítés')->badge(),
                TextColumn::make('supplier_country_code')->label('Ország'),
                TextColumn::make('itemMainGroup.name_hu')->label('Főcsoport'),
                TextColumn::make('product.model_code')->label('Modell'),
                TextColumn::make('priceList.code')->label('Árlista'),
                TextColumn::make('currency.code')->label('Pénznem'),
                TextColumn::make('value')->label('Érték'),
                IconColumn::make('active')->label('Aktív')->boolean(),
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
