<?php

namespace App\Filament\Resources\SizeRanges\RelationManagers;

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
                Select::make('size_id')
                    ->label('Méret')
                    ->relationship(
                        name: 'size',
                        titleAttribute: 'code',
                        modifyQueryUsing: fn ($query) => $query->orderBy('sort_order')
                    )
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('sort_order')
                    ->label('Sorrend')
                    ->numeric()
                    ->default(0),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('size.code')
                    ->label('Méret')
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Sorrend')
                    ->sortable(),
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