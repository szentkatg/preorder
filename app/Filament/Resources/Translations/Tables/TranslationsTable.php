<?php

namespace App\Filament\Resources\Translations\Tables;

use App\Models\Translation;
use App\Services\Translation\TranslationRegistry;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    ->formatStateUsing(
                        fn (?string $state): string =>
                            TranslationRegistry::entityLabel($state)
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make('entity_code')
                    ->label('Entitás kódja')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('field')
                    ->label('Mező')
                    ->formatStateUsing(
                        fn (
                            ?string $state,
                            Translation $record
                        ): string =>
                            TranslationRegistry::fieldLabel(
                                $record->entity,
                                $state,
                            )
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make('language_id')
                    ->label('Nyelv ID')
                    ->sortable(),

                TextColumn::make('language.code')
                    ->label('Nyelv')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('original_value')
                    ->label('Alapérték')
                    ->state(
                        fn (Translation $record): ?string =>
                            $record->originalValue()
                    )
                    ->placeholder('Nincs alapérték')
                    ->wrap(),

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
                SelectFilter::make('entity')
                    ->label('Entitás')
                    ->options(
                        TranslationRegistry::entityOptions()
                    )
                    ->searchable()
                    ->preload(),

                SelectFilter::make('field')
                    ->label('Mező')
                    ->options(
                        TranslationRegistry::allFieldOptions()
                    )
                    ->searchable()
                    ->preload(),

                SelectFilter::make('language_id')
                    ->label('Nyelv')
                    ->relationship('language', 'code')
                    ->searchable()
                    ->preload(),

                Filter::make('created_at')
                    ->label('Létrehozás dátuma')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label('Létrehozva ettől'),

                        DatePicker::make('created_until')
                            ->label('Létrehozva eddig'),
                    ])
                    ->query(
                        fn (
                            Builder $query,
                            array $data
                        ): Builder => $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (
                                    Builder $query,
                                    string $date
                                ): Builder => $query->whereDate(
                                    'created_at',
                                    '>=',
                                    $date,
                                ),
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (
                                    Builder $query,
                                    string $date
                                ): Builder => $query->whereDate(
                                    'created_at',
                                    '<=',
                                    $date,
                                ),
                            )
                    ),

                Filter::make('updated_at')
                    ->label('Módosítás dátuma')
                    ->schema([
                        DatePicker::make('updated_from')
                            ->label('Módosítva ettől'),

                        DatePicker::make('updated_until')
                            ->label('Módosítva eddig'),
                    ])
                    ->query(
                        fn (
                            Builder $query,
                            array $data
                        ): Builder => $query
                            ->when(
                                $data['updated_from'] ?? null,
                                fn (
                                    Builder $query,
                                    string $date
                                ): Builder => $query->whereDate(
                                    'updated_at',
                                    '>=',
                                    $date,
                                ),
                            )
                            ->when(
                                $data['updated_until'] ?? null,
                                fn (
                                    Builder $query,
                                    string $date
                                ): Builder => $query->whereDate(
                                    'updated_at',
                                    '<=',
                                    $date,
                                ),
                            )
                    ),
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