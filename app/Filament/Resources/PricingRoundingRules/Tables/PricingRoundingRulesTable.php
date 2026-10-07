<?php

namespace App\Filament\Resources\PricingRoundingRules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PricingRoundingRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pricingProject.name')->label('Árprojekt'),
                TextColumn::make('name')->label('Név'),
                TextColumn::make('price_type')->label('Ártípus')->badge(),
                TextColumn::make('priceList.code')->label('Árlista'),
                TextColumn::make('currency.code')->label('Pénznem'),
                TextColumn::make('round_to')->label('Kerekítés'),
                TextColumn::make('decimal_places')->label('Tizedes'),
                TextColumn::make('adjustment')->label('Korrekció'),
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
