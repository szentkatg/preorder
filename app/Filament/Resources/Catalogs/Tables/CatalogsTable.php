<?php

namespace App\Filament\Resources\Catalogs\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CatalogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('season.name')
                    ->label('Szezon')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('brand.name')
                    ->label('Márka')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('orderSheetType.name')
                    ->label('Rendelőlap típus')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Katalógus')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('pdf_file')
                    ->label('PDF')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('image_folder')
                    ->label('Képmappa')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('page_offset')
                    ->label('Oldal eltérés')
                    ->numeric()
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
            ->defaultSort('season.name')
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->filters([
                SelectFilter::make('season')
                    ->label('Szezon')
                    ->relationship('season', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('brand')
                    ->label('Márka')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('orderSheetType')
                    ->label('Rendelőlap típus')
                    ->relationship('orderSheetType', 'name')
                    ->searchable()
                    ->preload(),
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
