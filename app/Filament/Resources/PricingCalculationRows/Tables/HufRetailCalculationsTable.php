<?php

namespace App\Filament\Resources\PricingCalculationRows\Tables;

use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Services\Pricing\RetailPriceReviewService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class HufRetailCalculationsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('pricingProject.name')->label('Árprojekt'),
            TextColumn::make('priceList.code')->label('Árlista'),
            TextColumn::make('product.catalog_group_name_hu')->label('Katalógus csoport'),
            TextColumn::make('product.itemMainGroup.name_hu')->label('Főcsoport')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('product.model_code')->label('Modell kód'),
            TextColumn::make('product.name_hu')->label('Modell név'),
            TextColumn::make('color.name_hu')->label('Szín'),
            TextColumn::make('base_price')->label('Bekerülési HUF')->formatStateUsing(fn ($state) => self::formatPrice($state)),
            TextColumn::make('multiplier')->label('Kisker szorzó')
                ->formatStateUsing(fn ($state) => rtrim(rtrim((string) $state, '0'), '.')),
            TextColumn::make('unrounded_price')->label('Kerekítés előtti HUF')->formatStateUsing(fn ($state) => self::formatPrice($state)),
            TextColumn::make('pricingRoundingRule.name')->label('Kerekítési szabály')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('calculated_price')->label('Kalkulált kisker HUF')->formatStateUsing(fn ($state) => self::formatPrice($state)),
            TextInputColumn::make('manual_price')->label('Manuális HUF')->type('text')->inputMode('decimal')
                ->getStateUsing(fn (PricingCalculationRow $record) => $record->manual_price === null ? null : rtrim(rtrim($record->manual_price, '0'), '.'))
                ->rules(['nullable', 'numeric', 'gt:0', 'max:99999999999.9999'])
                ->tooltip('Írd át a manuális árat. Az üres mező visszaállítja a kalkulált árat. A módosítás után új jóváhagyás szükséges.')
                ->disabled(fn (PricingCalculationRow $record) => ! self::canEdit($record))
                ->updateStateUsing(function (PricingCalculationRow $record, mixed $state, $livewire) {
                    $updated = app(RetailPriceReviewService::class)->setManualPrice($record, $state);
                    $livewire->resetTable();

                    return $updated->manual_price === null ? null : rtrim(rtrim($updated->manual_price, '0'), '.');
                }),
            TextColumn::make('final_price')->label('Végleges kisker HUF')->formatStateUsing(fn ($state) => self::formatPrice($state))
                ->icon(fn (PricingCalculationRow $record) => match (true) {
                    (float) $record->final_price < (float) $record->calculated_price => 'heroicon-o-arrow-down',
                    (float) $record->final_price > (float) $record->calculated_price => 'heroicon-o-arrow-up',
                    default => null,
                })
                ->color(fn (PricingCalculationRow $record) => match (true) {
                    (float) $record->final_price < (float) $record->calculated_price => 'danger',
                    (float) $record->final_price > (float) $record->calculated_price => 'success',
                    default => null,
                }),
            IconColumn::make('requires_review')->label('Felülvizsgálandó')->boolean(),
            TextColumn::make('status')->label('Státusz')->badge()->formatStateUsing(fn ($state) => match ($state) {
                PricingCalculationRow::STATUS_CALCULATED => 'Kalkulált ár',
                PricingCalculationRow::STATUS_REVIEWED => 'Átnézett ár',
                PricingCalculationRow::STATUS_APPROVED => 'Jóváhagyott ár',
                PricingCalculationRow::STATUS_MODIFIED_APPROVED => 'Módosított jóváhagyott',
                PricingCalculationRow::STATUS_APPROVED_ROUND_2 => '2. körös jóváhagyott',
                PricingCalculationRow::STATUS_APPROVED_ROUND_3 => '3. körös jóváhagyott',
                default => $state,
            }),
        ])->filters([
            SelectFilter::make('pricing_project_id')->label('Árprojekt')->relationship('pricingProject', 'name')->searchable()->preload(),
            SelectFilter::make('price_list_id')->label('Árlista')->relationship('priceList', 'code')->searchable()->preload(),
            TernaryFilter::make('requires_review')->label('Felülvizsgálandó'),
        ])->recordActions([
            Action::make('retailDetails')->label('Részletek')->icon('heroicon-o-document-magnifying-glass')
                ->visible(fn (PricingCalculationRow $record) => Gate::allows('view', $record))
                ->modalWidth(Width::Screen)->modalHeading('HUF kisker kalkuláció részletei')
                ->modalSubmitAction(false)->modalCancelActionLabel('Bezárás')
                ->modalContent(fn (PricingCalculationRow $record) => view('filament.pricing.retail-calculation-details', ['record' => $record])),
            Action::make('approveRetail')->label(fn (PricingCalculationRow $record) => $record->requires_review ? 'Ár megtartása és jóváhagyása' : 'Jóváhagyás')
                ->icon('heroicon-o-check')->requiresConfirmation()
                ->schema([
                    Select::make('approval_status')->label('Átnézés / jóváhagyás szintje')->options([
                        PricingCalculationRow::STATUS_REVIEWED => 'Átnézett ár',
                        PricingCalculationRow::STATUS_APPROVED => 'Jóváhagyott ár',
                        PricingCalculationRow::STATUS_APPROVED_ROUND_2 => '2. körös jóváhagyott',
                        PricingCalculationRow::STATUS_APPROVED_ROUND_3 => '3. körös jóváhagyott',
                    ])->default(PricingCalculationRow::STATUS_APPROVED)->required(),
                ])
                ->visible(fn (PricingCalculationRow $record) => Gate::allows('update', $record))
                ->disabled(fn (PricingCalculationRow $record) => ! self::canEdit($record))
                ->action(fn (PricingCalculationRow $record, array $data) => app(RetailPriceReviewService::class)->approveCurrentPrice($record, $data['approval_status']))
                ->after(fn ($livewire) => $livewire->resetTable()),
            Action::make('acceptCalculatedRetail')->label('Kalkulált ár elfogadása')->icon('heroicon-o-arrow-path')->requiresConfirmation()
                ->modalDescription('A végleges ár a legutóbbi kalkulált ár lesz. A manuális vagy korábban megtartott ár helyett ezt hagyod jóvá.')
                ->visible(fn (PricingCalculationRow $record) => Gate::allows('update', $record)
                    && ($record->requires_review || $record->manual_price !== null || $record->retained_price !== null))
                ->disabled(fn (PricingCalculationRow $record) => ! self::canEdit($record))
                ->action(fn (PricingCalculationRow $record) => app(RetailPriceReviewService::class)->acceptCalculatedPrice($record))
                ->after(fn ($livewire) => $livewire->resetTable()),
        ])->defaultSort('updated_at', 'desc')->reorderableColumns()->columnManagerColumns(3)
            ->defaultPaginationPageOption(100)->paginationPageOptions([50, 100, 250, 500, 'all']);
    }

    private static function formatPrice(mixed $state): string
    {
        return rtrim(rtrim(number_format((float) $state, 4, ',', ' '), '0'), ',');
    }

    private static function canEdit(PricingCalculationRow $record): bool
    {
        return Gate::allows('update', $record) && $record->pricingProject?->active
            && $record->pricingProject->status !== PricingProject::STATUS_CLOSED;
    }
}
