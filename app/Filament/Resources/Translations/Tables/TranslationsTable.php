<?php

namespace App\Filament\Resources\Translations\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TranslationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('translation_id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('entity')
                    ->label('Entitás')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('entity_code')
                    ->label('Entitás kódja')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('field')
                    ->label('Mező')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('language_id')
                    ->label('Nyelv ID')
                    ->sortable(),

                TextColumn::make('language.code')
                    ->label('Nyelv')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('value')
                    ->label('Fordítás')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('created_at')
                    ->label('Létrehozva')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Módosítva')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->defaultSort('translation_id', 'desc')
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