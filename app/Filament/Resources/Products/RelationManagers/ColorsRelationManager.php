<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Support\AdminResourceTable;
use App\Models\Color;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ColorsRelationManager extends RelationManager
{
    protected static string $relationship = 'colors';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Színkód')
                    ->required()
                    ->maxLength(2),

                TextInput::make('name_hu')
                    ->label('Név HU')
                    ->required(),

                TextInput::make('name_en')
                    ->label('Név EN'),

                TextInput::make('sort_order')
                    ->label('Sorrend')
                    ->numeric()
                    ->default(0),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kód')
                    ->sortable(),

                TextColumn::make('name_hu')
                    ->label('Név HU')
                    ->searchable(),

                TextColumn::make('name_en')
                    ->label('Név EN'),

                TextColumn::make('sort_order')
                    ->label('Sorrend')
                    ->sortable(),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean(),
            ])
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

        return AdminResourceTable::configure(
            $table,
            Color::class,
            withViewAction: false,
        );
    }
}
