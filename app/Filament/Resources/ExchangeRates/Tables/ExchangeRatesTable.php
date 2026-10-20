<?php

namespace App\Filament\Resources\ExchangeRates\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExchangeRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('season.name')
                    ->label('Szezon')
                    ->searchable(),
                TextColumn::make('currency.code')
                    ->label('Pénznemkód')
                    ->searchable(),
                TextColumn::make('currency.name')
                    ->label('Pénznem neve')
                    ->getStateUsing(fn ($record) => $record->currency?->translate('name'))
                    ->searchable(),
                TextColumn::make('rate_to_huf')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('valid_from')
                    ->date()
                    ->sortable(),
                IconColumn::make('active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('season_id')->label('Szezon')->relationship('season', 'name')
                    ->multiple()->searchable()->preload(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
