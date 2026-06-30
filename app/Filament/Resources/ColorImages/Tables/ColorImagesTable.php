<?php

namespace App\Filament\Resources\ColorImages\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ColorImagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.model_code')
                    ->label('Modell')
                    ->searchable(),
                
                TextColumn::make('color.name_hu')
                    ->label('Szín')
                    ->searchable(),
                
                TextColumn::make('image_url')
                    ->label('Kép URL')
                    ->limit(50),
                
                TextColumn::make('sort_order')
                    ->label('Sorrend')
                    ->sortable(),
                
                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
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
