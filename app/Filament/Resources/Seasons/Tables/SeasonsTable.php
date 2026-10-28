<?php

namespace App\Filament\Resources\Seasons\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SeasonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kód'),

                TextColumn::make('name')
                    ->label('Név'),

                TextColumn::make('deadline')
                    ->label('Határidő')
                    ->date(),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean(),

                IconColumn::make('visible_to_partners')
                    ->label('Partnerportálon látható')
                    ->boolean(),
            ])
            ->filters([TernaryFilter::make('visible_to_partners')->label('Partnerportálon látható')])
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
