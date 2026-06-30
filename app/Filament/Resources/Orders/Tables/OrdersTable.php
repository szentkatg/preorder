<?php

namespace App\Filament\Resources\Orders\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('season.name')
                    ->label('Szezon')
                    ->sortable(),

                TextColumn::make('partner.name')
                    ->label('Partner')
                    ->searchable(),

                TextColumn::make('partnerAddress.name')
                    ->label('Cím / bolt')
                    ->searchable(),

                TextColumn::make('brand.name')
                    ->label('Márka'),

                TextColumn::make('orderSheetType.name_hu')
                    ->label('Rendelőlap'),

                TextColumn::make('priceList.code')
                    ->label('Árlista'),

                TextColumn::make('currency.code')
                    ->label('Pénznem'),


                TextColumn::make('total_ordered_units')
                    ->label('Rendelt egység')
                    ->numeric(),
                
                TextColumn::make('total_effective_quantity')
                    ->label('Tényleges db')
                    ->numeric(),
                
                TextColumn::make('total_value')
                    ->label('Érték')
                    ->numeric(2),

                TextColumn::make('status')
                    ->label('Státusz'),

                TextColumn::make('submitted_at')
                    ->label('Beküldve')
                    ->dateTime(),
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->filters([
                SelectFilter::make('season')
                    ->label('Szezon')
                    ->relationship('season', 'name'),

                SelectFilter::make('brand')
                    ->label('Márka')
                    ->relationship('brand', 'name'),

                SelectFilter::make('orderSheetType')
                    ->label('Rendelőlap')
                    ->relationship('orderSheetType', 'name_hu'),

                SelectFilter::make('status')
                    ->label('Státusz')
                    ->options([
                        'editing' => 'Kitöltés alatt',
                        'draft' => 'Piszkozat',
                        'submitted' => 'Beküldve',
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