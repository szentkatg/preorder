<?php

namespace App\Filament\Resources\PricingProjects\Tables;

use App\Filament\Support\Actions\CalculateAllProjectPricesAction;
use App\Filament\Support\Actions\PricingProjectLifecycleActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PricingProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('season.name')->label('Szezon'),
                TextColumn::make('name')->label('Név'),
                TextColumn::make('status')->label('Státusz')->badge()->formatStateUsing(fn ($state) => match ($state) {
                    'draft' => 'Tervezet', 'active' => 'Aktív', 'closed' => 'Lezárt', default => $state,
                }),
                TextColumn::make('closed_at')->label('Lezárva')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('closer.name')->label('Lezárta')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('active')->label('Aktív')->boolean(),
                TextColumn::make('updated_at')->label('Módosítva')->dateTime(),
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->recordActions([EditAction::make(),
                CalculateAllProjectPricesAction::make(),
                PricingProjectLifecycleActions::close(),
                PricingProjectLifecycleActions::reopen(),
                PricingProjectLifecycleActions::export(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()->authorizeIndividualRecords(fn ($record) => $record->isOpenForPricing() && ! $record->publications()->exists())]),
            ]);
    }
}
