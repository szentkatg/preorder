<?php

namespace App\Filament\Resources\PricingCalculationErrors\Tables;

use App\Models\PricingCalculationError;
use App\Models\PricingCalculationRow;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PricingCalculationErrorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Időpont')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('run_id')
                    ->label('Futás')
                    ->copyable()
                    ->limit(10)
                    ->tooltip(fn (PricingCalculationError $record): string => $record->run_id),
                TextColumn::make('pricingProject.name')
                    ->label('Árprojekt'),
                TextColumn::make('price_type')
                    ->label('Ártípus')
                    ->formatStateUsing(fn (string $state): string => self::priceTypes()[$state] ?? $state),
                TextColumn::make('severity')
                    ->label('Szint')
                    ->badge()
                    ->color(fn (string $state): string => $state === PricingCalculationError::SEVERITY_WARNING ? 'warning' : 'danger'),
                TextColumn::make('product.model_code')
                    ->label('Modell'),
                TextColumn::make('color.code')
                    ->label('Szín')
                    ->placeholder('Általános'),
                TextColumn::make('supplier.short_name')
                    ->label('Beszállító'),
                TextColumn::make('message')
                    ->label('Hiba oka')
                    ->wrap()
                    ->limit(160),
            ])
            ->filters([
                SelectFilter::make('pricing_project_id')
                    ->label('Árprojekt')
                    ->relationship('pricingProject', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('price_type')
                    ->label('Ártípus')
                    ->options(self::priceTypes()),
                SelectFilter::make('severity')
                    ->label('Szint')
                    ->options([
                        PricingCalculationError::SEVERITY_ERROR => 'Hiba',
                        PricingCalculationError::SEVERITY_WARNING => 'Figyelmeztetés',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make()->label('Törlés')
                    ->modalDescription('Csak a hibanapló bejegyzése törlődik, a kalkuláció és az árak nem változnak. A törlés nem vonható vissza.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Kijelölt hibák törlése')
                        ->modalDescription('A kijelölt hibanapló-bejegyzések végleg törlődnek. A kalkulációk és az árak nem változnak.'),
                ]),
            ]);
    }

    private static function priceTypes(): array
    {
        return [
            PricingCalculationRow::TYPE_COST => 'Bekerülési érték',
            PricingCalculationRow::TYPE_RETAIL => 'Kisker',
            PricingCalculationRow::TYPE_WHOLESALE => 'Nagyker',
            PricingCalculationRow::TYPE_DISTRIBUTOR => 'Disztribútor',
        ];
    }
}
