<?php

namespace App\Filament\Resources\Suppliers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier_id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('erp_partner_code')
                    ->label('ERP partnerkód')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('addrid')
                    ->label('Címkód')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('short_name')
                    ->label('Rövid név')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Teljes név')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('active')
                    ->label('Állapot')
                    ->options([
                        '1' => 'Aktív',
                        '0' => 'Inaktív',
                    ])
                    ->default('1')
                    ->placeholder('Minden'),
            ])
            ->defaultSort('erp_partner_code')
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