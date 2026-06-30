<?php

namespace App\Filament\Resources\Languages\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LanguagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kód')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name_hu')
                    ->label('Név HU')
                    ->searchable(),

                TextColumn::make('name_en')
                    ->label('Név EN'),

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