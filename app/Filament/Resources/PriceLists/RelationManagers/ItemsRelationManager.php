<?php

namespace App\Filament\Resources\PriceLists\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('season_id')
                    ->label('Szezon')
                    ->relationship('season', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('product_id')
                    ->label('Termék')
                    ->relationship('product', 'model_code')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('net_price')
                    ->label('Ár')
                    ->numeric()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('season.code')
                    ->label('Szezon')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.model_code')
                    ->label('Modell')
                    ->searchable(),

                TextColumn::make('product.name_hu')
                    ->label('Termék')
                    ->searchable(),

                TextColumn::make('net_price')
                    ->label('Ár')
                    ->numeric(decimalPlaces: 2),
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}