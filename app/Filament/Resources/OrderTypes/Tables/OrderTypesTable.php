<?php

namespace App\Filament\Resources\OrderTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrderTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kód')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Név')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('include_in_supplier_order')
                    ->label('Szállítói összesítés')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean()
                    ->sortable(),
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
