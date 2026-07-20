<?php

namespace App\Filament\Resources\RoundingRules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoundingRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Kód')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Megnevezés')
                    ->searchable(),

                IconColumn::make('include_assortments')
                    ->label('Gyűjtő')
                    ->boolean(),

                TextColumn::make('rounding_multiple')
                    ->label('Kerekítés'),

                TextColumn::make('rounding_mode')
                    ->label('Mód')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'threshold' => 'Küszöb',
                        'floor' => 'Lefelé',
                        'ceil' => 'Felfelé',
                        default => $state,
                    }),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean(),
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