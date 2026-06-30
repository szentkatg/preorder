<?php

namespace App\Filament\Resources\Skus\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SkusTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku_code')
                    ->label('Cikkszám')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('sku_name')
                    ->label('Megnevezés')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.model_code')
                    ->label('Modell')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.name_hu')
                    ->label('Termék')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('color.code')
                    ->label('Színkód')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('color.name_hu')
                    ->label('Szín')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('size.code')
                    ->label('Méret')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Típus')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Létrehozva')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Módosítva')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sku_code')
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->filters([
                SelectFilter::make('product')
                    ->label('Modell')
                    ->relationship('product', 'model_code')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('color')
                    ->label('Szín')
                    ->relationship('color', 'name_hu')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('size')
                    ->label('Méret')
                    ->relationship('size', 'code')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('type')
                    ->label('Típus')
                    ->options([
                        'normal' => 'Normál',
                        'assortment' => 'Gyűjtő',
                    ]),

                SelectFilter::make('active')
                    ->label('Aktív')
                    ->options([
                        1 => 'Igen',
                        0 => 'Nem',
                    ]),
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