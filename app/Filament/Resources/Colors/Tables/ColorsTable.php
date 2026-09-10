<?php

namespace App\Filament\Resources\Colors\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ColorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.model_code')
                    ->label('Modell'),

                TextColumn::make('code')
                    ->label('Színkód'),

                TextColumn::make('name_hu')
                    ->label('Név HU'),

                TextColumn::make('name_en')
                    ->label('Név EN'),

                TextColumn::make('sort_order')
                    ->label('Sorrend'),

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
